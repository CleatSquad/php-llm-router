# cleatsquad/php-llm-router

> **Renamed twice, and 4.0.0 is the one that touches your code.** This package
> was published as `mohaelmrabet/php-llm-router` up to 3.3.0, then as
> `cleatsquad/php-llm-router` from 3.4.0 — a Composer name change only. Since
> **4.0.0** the PHP namespace matches the vendor: `LlmRouter\` became
> `CleatSquad\LlmRouter\`. Not one class, signature or behaviour moved with it;
> only the `use` statements change.
>
> ```bash
> composer require cleatsquad/php-llm-router:^4.0
> ```
>
> Migration is one pass over your own code — see [UPGRADE.md](UPGRADE.md#3x--400).
> Staying on 3.4.0 is a valid choice — it keeps the old namespace — but new work
> lands on 4.x.


[![CI](https://github.com/CleatSquad/php-llm-router/actions/workflows/ci.yml/badge.svg)](https://github.com/CleatSquad/php-llm-router/actions/workflows/ci.yml)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-777bb4)](composer.json)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

Provider-agnostic LLM client for PHP. One interface, eight LLM drivers (Claude,
OpenAI, Gemini, Mistral, Groq, DeepSeek, Ollama, Kimi/Moonshot), pluggable
routing strategies (priority/fallback and round-robin load balancing),
decorators for retries, fail-over, caching, circuit breaking and rate
limiting, plus MCP and A2A client drivers for talking to tools and remote
agents, embedding drivers (with priority/fallback) for OpenAI/Gemini/
Mistral/Ollama, and audio transcription drivers for OpenAI/Groq — kept to
client-library scope (see "What this package does *not* do" below).

Any OpenAI-compatible endpoint — a LiteLLM proxy, vLLM, OpenRouter, an Azure
deployment — is reachable by pointing `OpenAiDriver` at its base URL; there is
no separate driver for those.

Extracted from a production chat/agent platform where it routes every LLM call
across local (Ollama) and cloud (Claude, OpenAI, Kimi) models, failing over
automatically when a provider is down, rate-limited, or out of credit.

## Install

```bash
composer require cleatsquad/php-llm-router
```

## Usage

```php
use CleatSquad\LlmRouter\Driver\ClaudeDriver;
use CleatSquad\LlmRouter\Driver\OllamaDriver;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Http\HttpClient;
use CleatSquad\LlmRouter\Routing\PriorityStrategy;

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
    priorities: ['ollama' => 25, 'openai' => 10, 'claude' => 1],       // fast-first
    qualityPriorities: ['claude' => 25, 'openai' => 15, 'ollama' => 1] // quality-first
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
| `KimiDriver` | Moonshot AI | tools |

Every driver implements `CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface`:
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
streams its own partial-response-per-chunk SSE format; OpenAI,
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

### Fail-over across providers

`PriorityStrategy::select()` picks *one* driver. When that driver then fails
mid-call, the caller is left re-implementing the "exclude it and pick the next
one" loop by hand. `FailoverDriver` is that loop, and it is itself an
`LLMDriverInterface`, so everything downstream keeps talking to one driver:

```php
use CleatSquad\LlmRouter\Driver\FailoverDriver;
use CleatSquad\LlmRouter\Routing\PriorityStrategy;

$router = new FailoverDriver(
    new PriorityStrategy(priorities: ['ollama' => 25, 'openai' => 10, 'claude' => 1]),
    [$ollama, $openai, $claude],
    logger: $logger, // optional PSR-3: one notice per fail-over, one error on exhaustion
);

$response = $router->chat($request); // tries ollama, then openai, then claude
```

On each failure it records a structured entry, excludes the failed driver, and
asks the strategy for another candidate from what is left. When every candidate
has failed it throws `AllDriversFailedException`, which carries the attempts as
live exception objects rather than a concatenated string:

```php
use CleatSquad\LlmRouter\Exception\AllDriversFailedException;
use CleatSquad\LlmRouter\Exception\RateLimitException;

try {
    $response = $router->chat($request);
} catch (AllDriversFailedException $e) {
    foreach ($e->getFailures() as $failure) {
        $failure['driverId'];  // 'ollama'
        $failure['exception']; // the RuntimeException that driver threw

        if ($failure['exception'] instanceof RateLimitException) {
            $failure['exception']->getRetryAfterSeconds(); // typed, never parsed from a message
        }
    }
}
```

Only `RuntimeException` triggers a fail-over — every driver here throws that on
a transport or API failure. Anything else (`TypeError`, `Error`, ...) is a
programming or environment defect that another provider cannot fix, so it
propagates untouched. Pass `shouldFailover` to narrow this further:

```php
$router = new FailoverDriver($strategy, $drivers,
    shouldFailover: static fn (RuntimeException $e, LLMDriverInterface $d): bool
        => !str_contains($e->getMessage(), 'context length'),
);
```

**Streaming stops failing over as soon as the first fragment is emitted.** Up
to that point a failure switches provider transparently; after it, the failure
propagates as-is, because an emitted fragment cannot be un-emitted and a fresh
provider would restart the answer on top of what the user already sees. The
caller decides what to do with a truncated response.

`FailoverDriver` composes with `CircuitBreakerDriver`: wrap each candidate in
one, and a failure recorded during fail-over is already visible to
`isAvailable()` on the very next iteration.

### Circuit breaker

`PriorityStrategy` only checks `isAvailable()` synchronously, per call — it
has no memory across requests, so a dead provider gets retried by every
caller until it's fixed. `CircuitBreakerDriver` wraps any driver and adds
that memory: after `$failureThreshold` consecutive `chat()`/`stream()`
failures it reports unavailable and fails fast — no network call — for
`$openSeconds` (or the dynamic delay extracted from HTTP 429 `Retry-After` headers via `RateLimitException`), resetting on the next success.

```php
use CleatSquad\LlmRouter\Driver\CircuitBreakerDriver;

$drivers = [
    new CircuitBreakerDriver(new ClaudeDriver($http, anthropicApiKey: $key), failureThreshold: 5, openSeconds: 60),
    new CircuitBreakerDriver(new OllamaDriver($http)),
];

$driver = $strategy->select($request, $drivers);
$response = $driver->chat($request); // throws immediately, no HTTP call, while the breaker is open
```

State is delegated to a `CircuitBreakerStoreInterface` (defaults to
`InMemoryCircuitBreakerStore`, scoped to the current process). Use the
included `RedisCircuitBreakerStore` (needs `ext-redis`), or implement the
interface against your own DB, to share breaker state across requests or
worker processes — the package itself stays storage-agnostic.

```php
use CleatSquad\LlmRouter\CircuitBreaker\RedisCircuitBreakerStore;

$store = new RedisCircuitBreakerStore(new Redis()); // connect() it yourself first
$driver = new CircuitBreakerDriver(new ClaudeDriver($http, anthropicApiKey: $key), $store);
```

### Retries with backoff

`RetryingDriver` wraps any driver and retries transient failures —
connection errors, timeouts, HTTP 429, HTTP 5xx — with exponential
backoff, up to `$maxAttempts`. Non-transient errors (401, 400, ...)
propagate immediately since retrying them just fails the same way again.

```php
use CleatSquad\LlmRouter\Driver\RetryingDriver;

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
use CleatSquad\LlmRouter\Driver\CachingDriver;

$driver = new CachingDriver(new ClaudeDriver($http, anthropicApiKey: $key), ttlSeconds: 300);
```

State is delegated to a `CacheStoreInterface` (defaults to
`InMemoryCacheStore`, which is process-local). See
[Sharing state across processes](#sharing-state-across-processes) for the
Redis, PSR-16 and APCu backends and what each does when it goes down.

### Rate limiting (RPM / TPM)

`RateLimitedDriver` wraps any driver with a requests-per-minute and/or
tokens-per-minute budget. A call that would exceed either limit blocks
(polling) until capacity frees up or `$maxWaitSeconds` runs out, instead
of firing straight into the provider's own 429.

```php
use CleatSquad\LlmRouter\Driver\RateLimitedDriver;

$driver = new RateLimitedDriver(
    new GroqDriver($http, groqApiKey: $key),
    maxRequestsPerMinute: 30,
    maxTokensPerMinute: 6000,
);
```

Token usage for `stream()` is only an estimate (input tokens only — these
drivers' `stream()` has no usage block to read from, since providers don't
send one over SSE). State is delegated to a `RateLimitStoreInterface`
(defaults to `InMemoryRateLimitStore`). Use the included
`RedisRateLimitStore` (needs `ext-redis`), or implement the interface
against your own DB, to share a quota across requests or processes — or
pass the same store instance to two `RateLimitedDriver`s wrapping the
same underlying driver to have them share one quota.

```php
use CleatSquad\LlmRouter\RateLimit\RedisRateLimitStore;

$store = new RedisRateLimitStore(new Redis()); // connect() it yourself first
$driver = new RateLimitedDriver(new GroqDriver($http, groqApiKey: $key), $store, maxRequestsPerMinute: 30);
```

### Load balancing & Routing strategies

`PriorityStrategy` answers "which provider first when they differ in quality/cost". `php-llm-router` provides a full suite of pluggable routing strategies implementing `RoutingStrategyInterface`:

| Strategy | Type | Metric required | Purpose |
| --- | --- | ---: | ---: |
| `priority` | ranking | no | explicit priority preference |
| `weighted` | ranking | no | weighted probabilistic distribution |
| `random` | ranking | no | simple shuffle / uniform random distribution |
| `least-busy` | ranking | active requests | route to driver with lowest active in-flight requests |
| `latency` | ranking | latency | route to driver with best rolling latency (EMA) |
| `cost` | ranking | pricing | route to driver with minimum estimated USD cost |
| `usage` | ranking | usage | route to driver with lowest accumulated usage |
| `reliability` | ranking | reliability | route to driver with highest success rate |
| `context-window` | constraint | model metadata | filter by prompt size vs context capacity limit |
| `capability` | constraint | capabilities | filter by driver capabilities (tools, vision, reasoning, streaming) |
| `quota` | constraint | quota state | protection against exhausted or low quota limits |
| `round-robin` | distribution | state | rotates evenly across equivalent deployments |

#### Strategy Factory

You can instantiate strategies dynamically using `RoutingStrategyFactory`:

```php
use CleatSquad\LlmRouter\Routing\RoutingStrategyFactory;

$factory = new RoutingStrategyFactory();

$priorityStrategy = $factory->create('priority', [
    'priorities' => ['openai' => 10, 'claude' => 5],
]);

$weightedStrategy = $factory->create('weighted', [
    'weights' => ['openai' => 70, 'anthropic' => 20, 'gemini' => 10],
]);

$latencyStrategy = $factory->create('latency');
```

```php
use CleatSquad\LlmRouter\Routing\RoundRobinStrategy;

$strategy = new RoundRobinStrategy(weights: ['key-a' => 2, 'key-b' => 1]); // key-a offered twice as often
$driver = $strategy->select($request, $drivers); // cycles, skipping unavailable ones
```

## Model selection

Each provider driver ships a pricing table that doubles as its model
catalogue — `getModels()` returns exactly what it can serve and cost.

```php
$driver->getModels(); // ['gpt-4o', 'gpt-4o-mini']
```

**A model you name explicitly is either used or refused.** Asking for one the
driver has no pricing for throws `UnknownModelException` rather than quietly
answering with the driver's default, which is what earlier versions did — you
asked for `gpt-5`, got `gpt-4o-mini`, and were billed for `gpt-4o-mini` with
nothing saying so.

```php
use CleatSquad\LlmRouter\Exception\UnknownModelException;

try {
    $response = $driver->chat(new LLMRequest($messages, model: 'gpt-5'));
} catch (UnknownModelException $e) {
    $e->requestedModel; // 'gpt-5'
    $e->knownModels;    // ['gpt-4o', 'gpt-4o-mini']
}
```

Passing no model at all is unchanged — that is declining to choose, not being
overruled, and resolves to the driver's default.

### Moving aliases

Providers publish aliases like `gemini-flash-latest` that resolve to whichever
version they currently point at. None of them is in a catalogue here, and that
is deliberate: an alias has no rate of its own, so any price recorded for it
becomes wrong the day it moves — silently, while `estimateCost()` keeps
reporting the old figure with confidence. Name the version you want, or
register the alias yourself with a rate you accept responsibility for.

### Models newer than this release

The tables lag behind the providers. Register a model with its pricing instead
of waiting for a new version of the package:

```php
$driver = new OpenAiDriver($http, openAiApiKey: $key, extraModelPricing: [
    'gpt-5' => ['input' => 0.00125, 'output' => 0.01], // USD per 1k tokens
]);
```

Your entries win over the shipped table, so this also corrects a stale price,
and they show up in `getModels()`.

### In a fail-over chain

`UnknownModelException` is a `RuntimeException`, so `FailoverDriver` fails over
on it: a chain asking for `gpt-5` skips the drivers that don't know it and
lands on the one that does — the routing the silent substitution used to hide.

### Two drivers resolve differently

- **`KimiDriver`** has no pricing table and forwards whatever name it is given.
- **`OllamaDriver`** resolves against the models actually installed on your
  local server, fuzzy-matching the closest one and never picking an embedding
  model as a chat fallback.

## Reasoning

Reasoning models think before answering. This package asks for that in one
vocabulary and translates it per provider, because they disagree on almost
everything: OpenAI, DeepSeek and Groq spell it `reasoning_effort`, Anthropic
`output_config.effort`, Gemini a token budget, Ollama a `think` level.

```php
use CleatSquad\LlmRouter\Enum\ReasoningEffort;

$response = $driver->chat(new LLMRequest(
    messages: $messages,
    reasoningEffort: ReasoningEffort::High,
    includeReasoning: true,   // also return the trace, not just spend tokens on it
));

$response->content;         // the answer
$response->reasoning;       // the trace, or null
$response->reasoningTokens; // thinking tokens, where the provider reports them
```

`ReasoningEffort` is `None`, `Low`, `Medium`, `High`, `XHigh` or `Max`. Drivers
supporting fewer levels clamp to their nearest one instead of dropping the
request. **Omitting `reasoningEffort` sends nothing at all**, leaving the
provider's own default in place — so this feature costs you nothing until you
ask for it.

Effort, not a token budget, is the portable abstraction. Anthropic's
`thinking.budget_tokens` is deprecated on Claude 4.6 and returns a 400 on
Claude 4.7 and later, so a budget-shaped API would already be broken.

### Reasoning while streaming

Reasoning never arrives through the values `stream()` yields — those stay the
visible answer, so existing loops keep working and no application accidentally
prints a model's scratch work to a user. Pass a callback instead:

```php
$request = new LLMRequest(
    messages: $messages,
    reasoningEffort: ReasoningEffort::High,
    includeReasoning: true,
    onReasoning: fn (string $fragment) => $ui->showThinking($fragment),
);

foreach ($driver->stream($request) as $chunk) {
    echo $chunk; // answer only
}
```

### Multi-turn: replay the trace

Anthropic, Mistral and Moonshot all require the reasoning trace to be sent back
on the following turn. Moonshot is explicit that dropping it during a
tool-calling loop degrades the model — and nothing in the response tells you it
happened.

Use `toMessage()` rather than hand-building the assistant entry, and the driver
re-emits the trace in its provider's native shape:

```php
$response = $driver->chat($request);

$messages[] = $response->toMessage(); // carries content, tool calls and the trace
$next = $driver->chat(new LLMRequest($messages, tools: $tools));
```

### What each provider actually does

| Driver | How it is asked | Trace returned? |
| --- | --- | --- |
| `ClaudeDriver` | `thinking: {type: "adaptive"}` + `output_config.effort` | Yes — needs `display: "summarized"`, which `includeReasoning` sets |
| `OpenAiDriver` | `reasoning_effort` | **No.** OpenAI keeps its reasoning private and bills for it |
| `DeepSeekDriver` | `thinking: {type: "enabled"}` + `reasoning_effort` | Yes, `reasoning_content` |
| `GeminiDriver` | `thinkingConfig.thinkingBudget` + `includeThoughts` | Yes, as parts flagged `thought: true` |
| `GroqDriver` | `reasoning_effort` + `reasoning_format: "parsed"` | Yes, `reasoning` |
| `OllamaDriver` | `think: "low"…"max"` | Yes, `message.thinking` |
| `MistralDriver` | `prompt_mode: "reasoning"` | Yes, `reasoning_content` |
| `KimiDriver` | nothing — reasoning is a property of the `k2-thinking` models | Yes, `reasoning_content` |

### Which models actually reason

A pricing entry can carry capability flags beside its rates:

```php
new OpenAiDriver($http, openAiApiKey: $key, extraModelPricing: [
    'gpt-5' => ['input' => 0.00125, 'output' => 0.01],                        // reasons
    'some-model' => ['input' => 0.0001, 'output' => 0.0002, 'reasoning' => false],
]);
```

`gpt-4o` and `gpt-4o-mini` ship marked `reasoning => false`, because OpenAI
rejects `reasoning_effort` on them. Asking them to reason raises
`UnsupportedReasoningException` — naming the model and what to do — instead of
letting the provider answer `400 Bad Request`. Entries you register are trusted
to reason unless they say otherwise, so a model this release predates is never
blocked by the check. `KimiDriver` and `OllamaDriver` have no catalogue and
perform no such check.

Anthropic's `claude-fable-5` and `claude-mythos-5` carry
`thinkingAlwaysOn => true`: their thinking cannot be switched off, so
`ReasoningEffort::None` omits the thinking block there rather than sending a
`disabled` instruction the API rejects.

**`supportsReasoning()` describes the driver, not your model.** It says this
driver knows how to express a reasoning request; whether the model you picked
honours it is a separate question. Sending `reasoning_effort` to a
non-reasoning model (`gpt-4o`, say) earns a 400 from the provider — the error
is surfaced as-is rather than guessed at, because a per-model capability table
would be stale within weeks.

## Sharing state across processes

Three decorators keep state between calls: `CachingDriver` (cached responses),
`CircuitBreakerDriver` (failure counts) and `RateLimitedDriver` (quota
counters). Each delegates to a store interface, and each defaults to an
in-memory implementation that lives for exactly one PHP process.

That default is right for a CLI script and wrong for PHP-FPM: with eight
workers you get eight independent caches, eight breakers that each have to
rediscover the same outage, and eight quotas that each admit the full limit.
Pass a shared store instead.

### With Redis

The most complete option — shared across processes *and* machines:

```php
use CleatSquad\LlmRouter\Cache\RedisCacheStore;
use CleatSquad\LlmRouter\CircuitBreaker\RedisCircuitBreakerStore;
use CleatSquad\LlmRouter\RateLimit\RedisRateLimitStore;

$redis = new Redis();
$redis->connect('127.0.0.1', 6379); // connect it yourself; the stores never do

$driver = new ClaudeDriver($http, anthropicApiKey: $key);

$driver = new CachingDriver($driver, new RedisCacheStore($redis, logger: $logger), ttlSeconds: 300);
$driver = new CircuitBreakerDriver($driver, new RedisCircuitBreakerStore($redis, logger: $logger));
$driver = new RateLimitedDriver($driver, new RedisRateLimitStore($redis), maxRequestsPerMinute: 30);
```

Every store takes a key prefix as its second argument, so several applications
can share one Redis instance without colliding:

```php
$store = new RedisCacheStore($redis, prefix: 'myapp:llm:cache:');
```

`RedisRateLimitStore` counts with `HINCRBY` on a per-window hash, so two
workers racing for the last slot produce two increments and one of them is
refused — a read-then-write quota would let both through.

### Without Redis

Any PSR-16 cache works for the response cache and the circuit breaker —
filesystem, APCu, Memcached, PDO, whatever your framework already configures:

```php
use CleatSquad\LlmRouter\Cache\Psr16CacheStore;
use CleatSquad\LlmRouter\CircuitBreaker\Psr16CircuitBreakerStore;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

$psr16 = new Psr16Cache(new FilesystemAdapter());

$driver = new CachingDriver($driver, new Psr16CacheStore($psr16), ttlSeconds: 300);
$driver = new CircuitBreakerDriver($driver, new Psr16CircuitBreakerStore($psr16));
```

The quota is deliberately **not** available over PSR-16: the interface has no
atomic increment, so a PSR-16 quota would be a read-modify-write pretending to
be a shared one. Use APCu instead, which does have an atomic increment:

```php
use CleatSquad\LlmRouter\RateLimit\ApcuRateLimitStore;

$driver = new RateLimitedDriver($driver, new ApcuRateLimitStore(), maxRequestsPerMinute: 30);
```

`ApcuRateLimitStore` is shared across the PHP-FPM workers of **one machine**.
With four app servers and a limit of 30/min the provider sees up to 120/min, so
either divide the ceiling by your server count or use Redis.

Nothing stops you from mixing backends — a Redis quota with a filesystem cache
is a perfectly reasonable configuration.

### Writing your own

The three interfaces are small (`CacheStoreInterface`,
`CircuitBreakerStoreInterface`, `RateLimitStoreInterface`), so a DynamoDB or
Postgres store is a short class. If your backend has a real atomic increment,
implement `AtomicRateLimitStoreInterface` as well — `RateLimitedDriver` detects
it and takes the atomic path automatically.

Shared state is only ever stored as JSON or as integers. No store in this
package calls `unserialize()`, so a corrupted or hostile value can never decide
which PHP class gets instantiated; it is rejected and read as absent.

### What each store does when it goes down

| Store | Behaviour on backend failure | Why |
| --- | --- | --- |
| `RedisCacheStore`, `Psr16CacheStore` | **fail-open** — reads miss, writes are dropped, the LLM call proceeds | A cache is an optimisation. Answering slowly beats not answering. |
| `RedisCircuitBreakerStore`, `Psr16CircuitBreakerStore` | **fail-open** — reads as closed, the call is attempted | The breaker spares callers a doomed call, it does not authorise them. Failing closed would turn one Redis blip into an outage of every provider at once. |
| `RedisRateLimitStore`, `ApcuRateLimitStore` | **fail-closed** — the error propagates and the call fails | A quota is a protection mechanism. Silently admitting unlimited traffic because the coordination backend blinked is not a safe degradation. |
| `InMemory*` | n/a — no backend to lose | |

Fail-open never means failing silently: pass a PSR-3 logger to any of the
Redis/PSR-16 stores and every degradation is recorded at warning level.

## MCP and A2A drivers

Two more driver families beyond LLM chat, following the same
`getId()/getType()/isAvailable()/healthCheck()/getMetadata()` base
contract (`CleatSquad\LlmRouter\Contract\Driver\DriverInterface`), so they compose
with the rest of the package (health checks, driver registries, etc.)
without the router needing to know about them specifically.

**`McpClientDriver`** — a [Model Context Protocol](https://modelcontextprotocol.io)
client, backed by the official `mcp/sdk`. Connects to an MCP server over
stdio (spawns a local process) or HTTP, lists its tools/prompts/resources,
and calls tools:

```php
use CleatSquad\LlmRouter\Driver\McpClientDriver;

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
use CleatSquad\LlmRouter\Driver\A2AClientDriver;
use CleatSquad\LlmRouter\Http\HttpClient;

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
don't have one).

```php
use CleatSquad\LlmRouter\Driver\OpenAiEmbeddingDriver;
use CleatSquad\LlmRouter\DTO\EmbeddingRequest;
use CleatSquad\LlmRouter\Http\HttpClient;

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
use CleatSquad\LlmRouter\Driver\FallbackEmbeddingDriver;

$driver = new FallbackEmbeddingDriver([$ollama, $openai, $mistral]); // priority order, highest first
$response = $driver->embed(EmbeddingRequest::forText('Hello, world')); // tries $ollama, falls back on failure
```

## Audio transcription

`OpenAiAudioDriver` and `GroqAudioDriver` implement `AudioDriverInterface`
(`transcribe()`, `getModels()`, `estimateCost()`) — the only two providers
here with a real speech-to-text endpoint:

```php
use CleatSquad\LlmRouter\Driver\OpenAiAudioDriver;
use CleatSquad\LlmRouter\DTO\AudioTranscriptionRequest;
use CleatSquad\LlmRouter\Http\HttpClient;

$driver = new OpenAiAudioDriver(new HttpClient(), openAiApiKey: getenv('OPENAI_API_KEY'));

$response = $driver->transcribe(AudioTranscriptionRequest::fromFile('/path/to/voice-note.ogg'));
echo $response->text;
```

`FallbackAudioDriver` wraps them the same way `FallbackEmbeddingDriver` does —
priority order, falls through on failure/unavailability.

## Failure semantics

What is safe to replay, what is not, and where the line sits.

### Replayable

| Failure | Retried by | Notes |
| --- | --- | --- |
| Connection error, DNS failure, timeout | `RetryingDriver` | Nothing reached the provider, or nothing came back. |
| HTTP 5xx | `RetryingDriver` | The provider's own fault, often transient. |
| HTTP 429 (`RateLimitException`) | `RetryingDriver` | Waits the provider's `Retry-After` when it sent one, capped by `maxDelaySeconds`; otherwise the jittered exponential backoff. |

Backoff is exponential with decorrelated jitter (50–100% of the computed
delay), so workers riding out the same outage don't resynchronise onto one
schedule and hammer the provider in lockstep.

### Not replayable

| Failure | Behaviour |
| --- | --- |
| HTTP 4xx other than 429 (401, 400, 404, ...) | Propagates immediately — a bad key or a malformed request fails identically on the second try. |
| `TypeError`, `Error`, any non-`RuntimeException` | Propagates immediately. A programming or environment defect is not fixed by another provider. |
| Any failure after the first streamed fragment | Propagates immediately. See below. |

### The streaming rule

Once `stream()` has yielded a single fragment to the caller, **no automatic
retry or fail-over happens for that call** — not in `RetryingDriver`, not in
`FailoverDriver`, regardless of attempts or candidates remaining.

An emitted fragment cannot be un-emitted. The user has already seen it, and a
retry restarts the answer from the top, producing duplicated or contradictory
output. So the failure is handed to the caller, who has the context to decide:
show the partial answer as truncated, offer a manual retry, or discard it.

Before the first fragment, nothing is visible yet and both retry and fail-over
apply normally.

### Rate limits

A 429 is surfaced as `CleatSquad\LlmRouter\Exception\RateLimitException`, with the delay
as a typed property — `getRetryAfterSeconds()` — never as text to be parsed out
of a message. `Retry-After` is read in both forms RFC 9110 allows (a delay in
seconds, or an HTTP date); a past date and a negative value both read as 0, and
anything unparseable reads as `null`, meaning "no usable value, use your own
backoff" rather than "retry immediately".

`CircuitBreakerDriver` uses the same figure: when the failure that trips the
breaker carries a `Retry-After`, the circuit stays open for that long instead
of for the configured `$openSeconds`.

### Exhaustion

When every candidate has failed, `FailoverDriver` throws
`AllDriversFailedException` carrying each attempt as `['driverId' => string,
'exception' => RuntimeException]`. Callers are expected to branch on those
objects; the exception message is a summary for logs, not an API.

## What this package does *not* do

- **No DB-backed usage/cost tracking.** `LLMResponse::$costUsd` and
  `CostEstimate` give you the numbers per call; persisting and aggregating
  them is an application concern (schema, retention, reporting all vary too
  much to standardize here).
- **No prompt templating, no agent/tool-execution loop.** This is a thin,
  uniform transport layer over each provider's chat endpoint — orchestration
  belongs one layer up.

## Requirements

- PHP >= 8.2 (tested on 8.2, 8.3 and 8.4)

### Dependencies

| Package | Required by | Scope |
| --- | --- | --- |
| `guzzlehttp/guzzle` ^7.8 | `Http\HttpClient`, every HTTP driver, and `RetryingDriver` (which reads Guzzle exception types to judge retryability) | All LLM, embedding and audio drivers |
| `psr/log` ^3.0 | `FailoverDriver` and the shared stores, for optional logging | Interfaces only |
| `psr/simple-cache` ^3.0 | `Psr16CacheStore`, `Psr16CircuitBreakerStore` | Interfaces only |
| `mcp/sdk` ^0.7 | `Driver\McpClientDriver` — **one file** | Pulls the largest share of the transitive tree (opis/*, symfony/uid, psr/http-server-*) |

Optional extensions:

- `ext-redis` — `RedisCacheStore`, `RedisCircuitBreakerStore`, `RedisRateLimitStore`
- `ext-apcu` — `ApcuRateLimitStore`

### Known limits

- **`mcp/sdk` is a heavy dependency for one driver.** It is required rather
  than optional so that `McpClientDriver` either works or doesn't exist —
  a driver that silently degrades is worse than an unused dependency. If it
  becomes a problem for your dependency tree, `Driver/McpClientDriver.php` is
  the only file to remove, and splitting it into a companion package is
  tracked as the next candidate change.
- **Streamed token usage is an estimate.** Providers send no usage block over
  SSE, so `RateLimitedDriver` counts input tokens only for `stream()`.
- **`ApcuRateLimitStore` enforces a per-machine quota**, not a fleet-wide one.
- **`RoundRobinStrategy` is stateful per instance.** Its cursor lives in the
  object, so rotation is per-process unless you keep one instance around.

## License

MIT
