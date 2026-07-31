# mohaelmrabet/php-llm-router

[![CI](https://github.com/mohaelmrabet/php-llm-router/actions/workflows/ci.yml/badge.svg)](https://github.com/mohaelmrabet/php-llm-router/actions/workflows/ci.yml)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-777bb4)](composer.json)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

Provider-agnostic LLM client for PHP. One interface, nine LLM drivers (Claude,
OpenAI, Gemini, Mistral, Groq, DeepSeek, Ollama, LiteLLM, Kimi/Moonshot),
pluggable routing strategies (priority/fallback and round-robin load
balancing), decorators for retries, caching, circuit breaking and rate
limiting, plus MCP and A2A client drivers for talking to tools and remote
agents, and embedding drivers for OpenAI/Gemini/Mistral/Ollama — the PHP
equivalent of what LiteLLM's SDK does for Python, kept to client-library
scope (see "What this package does *not* do" below).

Extracted from a production chat/agent platform where it routes every LLM call
across local (Ollama) and cloud (Claude, OpenAI, Kimi, LiteLLM-proxied) models,
failing over automatically when a provider is down, rate-limited, or out of
credit.

## Install

```bash
composer require mohaelmrabet/php-llm-router
```

## Usage

```php
use LlmRouter\Driver\ClaudeDriver;
use LlmRouter\Driver\OllamaDriver;
use LlmRouter\DTO\LLMRequest;
use LlmRouter\Http\HttpClient;
use LlmRouter\Routing\PriorityStrategy;

$http = new HttpClient();

$drivers = [
    new OllamaDriver($http, ollamaUrl: 'http://localhost:11434', ollamaModel: 'llama3'),
    new ClaudeDriver($http, anthropicApiKey: getenv('ANTHROPIC_API_KEY') ?: ''),
];

// Higher number = tried first, as long as isAvailable() is true.
$strategy = new PriorityStrategy(priorities: ['ollama' => 10, 'claude' => 5]);

$request = new LLMRequest(messages: [
    ['role' => 'user', 'content' => 'Say hello in one word.'],
]);

$driver = $strategy->select($request, $drivers);
$response = $driver->chat($request);

echo $response->content; // "Hello"
echo $response->costUsd; // 0.0 for Ollama, real $ for Claude
```

### Fallback across two priority tiers

`LLMRequest::$preferQuality` lets one strategy instance serve two different
orderings — e.g. "fast/cheap for classifier calls" vs. "best quality for the
user-facing reply" — without instantiating two strategies:

```php
$strategy = new PriorityStrategy(
    priorities: ['ollama' => 25, 'litellm' => 10, 'claude' => 1],       // fast-first
    qualityPriorities: ['litellm' => 25, 'claude' => 15, 'ollama' => 1] // quality-first
);

$classifierDriver = $strategy->select(new LLMRequest(messages: $msgs), $drivers);
$replyDriver = $strategy->select(new LLMRequest(messages: $msgs, preferQuality: true), $drivers);
```

## Drivers included

| Driver | Provider | Notes |
|---|---|---|
| `ClaudeDriver` | Anthropic Messages API | tools, vision, extended reasoning |
| `OpenAiDriver` | OpenAI Chat Completions | tools, vision |
| `GeminiDriver` | Google Gemini (generateContent) | tools, vision — own wire format, not OpenAI-compatible |
| `MistralDriver` | Mistral AI | tools |
| `GroqDriver` | Groq (direct, no proxy) | tools |
| `DeepSeekDriver` | DeepSeek | tools, reasoning (`deepseek-reasoner`) |
| `OllamaDriver` | Local Ollama | free, fuzzy-matches the closest locally-pulled model |
| `LiteLLMDriver` | A LiteLLM proxy | fronts whatever LiteLLM itself routes to |
| `KimiDriver` | Moonshot AI | tools |

Every driver implements `LlmRouter\Contract\Driver\LLMDriverInterface`:
`chat()`, `stream()`, `getModels()`, `isAvailable()`, `healthCheck()`,
`estimateCost()`, and `supportsStreaming()/Tools()/Vision()/Reasoning()`
capability flags.

Write your own driver for another provider by implementing the same
interface — nothing else in this package needs to know about it.

### Streaming

```php
foreach ($driver->stream($request) as $textChunk) {
    echo $textChunk;
}
```

Ollama streams newline-delimited JSON; Claude uses Anthropic's own
event-typed SSE framing (`content_block_delta` / `message_stop`); Gemini
streams its own partial-response-per-chunk SSE format; LiteLLM, OpenAI,
Kimi, Mistral, Groq and DeepSeek all share the OpenAI-compatible
`data: {json}` SSE framing via `Driver\Concern\ParsesChatCompletionSse`
(named after the wire format, not the vendor — any OpenAI-compatible API
speaks it).

### Tool calls while streaming

Every provider sends a streamed tool call as incremental fragments — an
`id`/`name` in one delta, then the JSON `arguments` string arriving
character-by-character-ish across several more — instead of the single
complete object `chat()` gets back in one shot. `stream()` accumulates
these under the hood and hands them back once the generator is done, via
`Generator::getReturn()`:

```php
$content = '';
$gen = $driver->stream($request);
foreach ($gen as $textChunk) {
    $content .= $textChunk;
    echo $textChunk;
}

$toolCalls = $gen->getReturn(); // same shape as LLMResponse::$toolCalls, or null
if ($toolCalls !== null) {
    // ... dispatch each call, same as you would from a non-streamed chat() response
}
```

`null` either means the model didn't call a tool this turn, or the driver
never supports tool calls at all (Ollama's `stream()` always returns
`null` — its own `chat()` never parses tool calls either, native
function-calling support across Ollama models is too inconsistent to rely
on).

### Circuit breaker

`PriorityStrategy` only checks `isAvailable()` synchronously, per call — it
has no memory across requests, so a dead provider gets retried by every
caller until it's fixed. `CircuitBreakerDriver` wraps any driver and adds
that memory: after `$failureThreshold` consecutive `chat()`/`stream()`
failures it reports unavailable and fails fast — no network call — for
`$openSeconds`, resetting on the next success.

```php
use LlmRouter\Driver\CircuitBreakerDriver;

$drivers = [
    new CircuitBreakerDriver(new ClaudeDriver($http, anthropicApiKey: $key), failureThreshold: 5, openSeconds: 60),
    new CircuitBreakerDriver(new OllamaDriver($http)),
];

$driver = $strategy->select($request, $drivers);
$response = $driver->chat($request); // throws immediately, no HTTP call, while the breaker is open
```

State is delegated to a `CircuitBreakerStoreInterface` (defaults to
`InMemoryCircuitBreakerStore`, scoped to the current process). Implement
that interface against Redis/DB to share breaker state across requests or
worker processes — the package itself stays storage-agnostic.

### Retries with backoff

`RetryingDriver` wraps any driver and retries transient failures —
connection errors, timeouts, HTTP 429, HTTP 5xx — with exponential
backoff, up to `$maxAttempts`. Non-transient errors (401, 400, ...)
propagate immediately since retrying them just fails the same way again.

```php
use LlmRouter\Driver\RetryingDriver;

$driver = new RetryingDriver(
    new OpenAiDriver($http, openAiApiKey: $key),
    maxAttempts: 3,
    baseDelaySeconds: 0.5, // doubles each attempt, capped at maxDelaySeconds
);

$response = $driver->chat($request);
```

For `stream()`, only a failure *before any chunk reached the caller* is
retried — once content has started flowing, a fresh attempt could
duplicate or corrupt what the caller already received, so it propagates
immediately instead, regardless of attempts remaining.

### Response caching

`CachingDriver` wraps any driver and caches `chat()` responses: an
identical request (same messages/model/temperature/maxTokens/tools) within
the TTL window returns the previous `LLMResponse` instead of paying for
another call. `stream()` always bypasses the cache — buffering a whole
response before the first byte reaches the caller would defeat the point
of streaming.

```php
use LlmRouter\Driver\CachingDriver;

$driver = new CachingDriver(new ClaudeDriver($http, anthropicApiKey: $key), ttlSeconds: 300);
```

State is delegated to a `CacheStoreInterface` (defaults to
`InMemoryCacheStore`); implement it against Redis/DB to share the cache
across requests or processes.

### Rate limiting (RPM / TPM)

`RateLimitedDriver` wraps any driver with a requests-per-minute and/or
tokens-per-minute budget. A call that would exceed either limit blocks
(polling) until capacity frees up or `$maxWaitSeconds` runs out, instead
of firing straight into the provider's own 429.

```php
use LlmRouter\Driver\RateLimitedDriver;

$driver = new RateLimitedDriver(
    new GroqDriver($http, groqApiKey: $key),
    maxRequestsPerMinute: 30,
    maxTokensPerMinute: 6000,
);
```

Token usage for `stream()` is only an estimate (input tokens only — these
drivers' `stream()` has no usage block to read from, since providers don't
send one over SSE). State is delegated to a `RateLimitStoreInterface`
(defaults to `InMemoryRateLimitStore`); implement it against Redis/DB to
share a quota across requests or processes — or pass the same store
instance to two `RateLimitedDriver`s wrapping the same underlying driver
to have them share one quota.

### Load balancing across equivalent deployments

`PriorityStrategy` answers "which provider first when they differ in
quality/cost". `RoundRobinStrategy` answers a different question: how do
you spread load across *interchangeable* deployments of the same model —
e.g. three OpenAI API keys behind three `OpenAiDriver` instances — instead
of always hitting the first one.

```php
use LlmRouter\Routing\RoundRobinStrategy;

$strategy = new RoundRobinStrategy(weights: ['key-a' => 2, 'key-b' => 1]); // key-a offered twice as often
$driver = $strategy->select($request, $drivers); // cycles, skipping unavailable ones
```

## MCP and A2A drivers

Two more driver families beyond LLM chat, following the same
`getId()/getType()/isAvailable()/healthCheck()/getMetadata()` base
contract (`LlmRouter\Contract\Driver\DriverInterface`), so they compose
with the rest of the package (health checks, driver registries, etc.)
without the router needing to know about them specifically.

**`McpClientDriver`** — a [Model Context Protocol](https://modelcontextprotocol.io)
client, backed by the official `mcp/sdk`. Connects to an MCP server over
stdio (spawns a local process) or HTTP, lists its tools/prompts/resources,
and calls tools:

```php
use LlmRouter\Driver\McpClientDriver;

$mcp = new McpClientDriver([
    'id' => 'filesystem',
    'transport' => 'stdio',
    'command' => 'npx',
    'args' => ['-y', '@modelcontextprotocol/server-filesystem', '/tmp'],
]);

$mcp->connect();
$tools = $mcp->listTools();
$result = $mcp->callTool('read_file', ['path' => '/tmp/notes.txt']);
$mcp->disconnect();
```

**`A2AClientDriver`** — an [A2A (Agent2Agent)](https://a2a-protocol.org)
client: discovers a remote agent's Agent Card, then talks to it over the
protocol's JSON-RPC 2.0 wire format (`message/send`, `message/stream`,
`tasks/get`, `tasks/cancel`):

```php
use LlmRouter\Driver\A2AClientDriver;
use LlmRouter\Http\HttpClient;

$agent = new A2AClientDriver(new HttpClient(), 'https://agent.example.com');

$response = $agent->execute('Book a table for 4 at 8pm');
echo $response->output; // text extracted from the resulting task/message
// $response->metadata carries taskId/contextId/state for follow-up calls

foreach ($agent->stream('Summarize this thread') as $chunk) {
    echo $chunk;
}
```

`McpClientDriver` implements `MCPDriverInterface`; `A2AClientDriver`
implements `A2ADriverInterface`, which itself extends the protocol-agnostic
`AgentDriverInterface` (`execute()`, `getCapabilities()`,
`supportsStreaming()`). Write your own driver for another MCP transport or
agent protocol the same way you would for an LLM provider.

## Embeddings

Four drivers implement `EmbeddingDriverInterface` (`embed()`, `getModels()`,
`estimateCost()`) for the providers that actually offer an embeddings
endpoint — `OpenAiEmbeddingDriver`, `GeminiEmbeddingDriver`,
`MistralEmbeddingDriver`, `OllamaEmbeddingDriver` (Claude/Groq/DeepSeek/Kimi
don't have one; LiteLLM proxies whichever of these you point it at).

```php
use LlmRouter\Driver\OpenAiEmbeddingDriver;
use LlmRouter\DTO\EmbeddingRequest;
use LlmRouter\Http\HttpClient;

$driver = new OpenAiEmbeddingDriver(new HttpClient(), openAiApiKey: getenv('OPENAI_API_KEY'));

$response = $driver->embed(EmbeddingRequest::forText('Hello, world'));
$vector = $response->first(); // array<float>

// Batch — one vector per input, same order:
$response = $driver->embed(new EmbeddingRequest(['doc one', 'doc two', 'doc three']));
$vectors = $response->embeddings;
```

`FallbackEmbeddingDriver` wraps an ordered list of them — always tries the
first, falling through to the next only when a driver is unavailable or its
`embed()` call throws:

```php
use LlmRouter\Driver\FallbackEmbeddingDriver;

$driver = new FallbackEmbeddingDriver([$ollama, $openai, $mistral]); // priority order, highest first
$response = $driver->embed(EmbeddingRequest::forText('Hello, world')); // tries $ollama, falls back on failure
```

## What this package does *not* do

- **No DB-backed usage/cost tracking.** `LLMResponse::$costUsd` and
  `CostEstimate` give you the numbers per call; persisting and aggregating
  them is an application concern (schema, retention, reporting all vary too
  much to standardize here).
- **No prompt templating, no agent/tool-execution loop.** This is a thin,
  uniform transport layer over each provider's chat endpoint — orchestration
  belongs one layer up.

## Requirements

- PHP >= 8.2
- `guzzlehttp/guzzle` ^7.8
- `mcp/sdk` ^0.7 (for `McpClientDriver`)

## License

MIT
