# mohaelmrabet/php-llm-router

Provider-agnostic LLM client for PHP. One interface, five drivers (Claude, OpenAI,
Ollama, LiteLLM, Kimi/Moonshot), and a pluggable routing strategy for
priority/fallback selection — the PHP equivalent of what LiteLLM does for Python.

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
event-typed SSE framing (`content_block_delta` / `message_stop`); LiteLLM,
OpenAI and Kimi share the OpenAI-compatible `data: {json}` SSE framing via
`Driver\Concern\ParsesChatCompletionSse` (named after the wire format, not
the vendor — LiteLLM, Kimi, Groq and others all speak it too).

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

## License

MIT
