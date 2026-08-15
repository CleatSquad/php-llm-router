# Upgrade guide

## 4.x → 5.0.0

**Major Architectural Upgrade: Composable Routing Decision Engine.**

`php-llm-router` v5 replaces the strategy-based routing model (`RoutingStrategyInterface::select()`) with a **Composable Routing Decision Engine** (`RoutingEngine::decide()`).

### Quick Summary of Changes

1. **Routing Engine & Policy**: `RoutingEngine` processes `RoutingPolicy` instances built of explicit `ConstraintInterface[]`, `RankerInterface[]`, and a `SelectorInterface`.
2. **Immutable Candidates**: `Candidate` is now an immutable value object (`id`, `name`, `driver`).
3. **Accumulative Evaluation State**: `CandidateEvaluation` tracks candidate eligibility, constraint `rejections`, and composite `RankScore` results.
4. **Structured Decision Telemetry**: `RoutingDecision` provides complete inspectability (`$decision->selected`, `$decision->orderedCandidates`, `$decision->evaluations`, `$decision->getFallbacks()`).
5. **NoEligibleCandidateException**: Thrown when zero candidates survive constraints, carrying `$e->getEvaluations()` for complete failure telemetry.

### Upgrading Code

If you were using `PriorityStrategy` or factory-instantiated strategies in v4:

#### Before (v4):
```php
use CleatSquad\LlmRouter\Routing\PriorityStrategy;

$strategy = new PriorityStrategy(priorities: ['ollama' => 10, 'claude' => 5]);
$driver = $strategy->select($request, $drivers);
```

#### After (v5 Native Policy):
```php
use CleatSquad\LlmRouter\Engine\RoutingEngine;
use CleatSquad\LlmRouter\Policy\RoutingPolicy;
use CleatSquad\LlmRouter\Constraint\CapabilityConstraint;
use CleatSquad\LlmRouter\Ranker\PriorityRanker;
use CleatSquad\LlmRouter\Selector\BestCandidateSelector;

$policy = new RoutingPolicy(
    constraints: [new CapabilityConstraint()],
    rankers: [new PriorityRanker(['ollama' => 10, 'claude' => 5])],
    selector: new BestCandidateSelector()
);

$engine = new RoutingEngine($policy);
$decision = $engine->decide($request, $drivers);
$driver = $decision->selected->driver;
```

### Backward Compatibility & Adapters

- **Existing `RoutingStrategyInterface` implementations**: Continue to work via `LegacyStrategyAdapter::toPolicy($customStrategy)`.
- **v5 Policy to v4 interface**: Use `RoutingPolicyAdapter($v5Policy)` to wrap a v5 policy into a `RoutingStrategyInterface`.
- **`RoutingStrategyFactory`**: Retained and updated to instantiate `RoutingPolicyAdapter` instances wrapping v5 policies under the hood.

For detailed architecture explanations, see [docs/v5-architecture.md](docs/v5-architecture.md) and [docs/v5-migration.md](docs/v5-migration.md).

---

## 3.x → 4.0.0

**One breaking change, and it is mechanical: the namespace gained its vendor
prefix.** `LlmRouter\` is now `CleatSquad\LlmRouter\`. No class was renamed,
moved, split or removed; no signature, constructor order or default changed; the
PHP floor stays at 8.2 and the dependencies are untouched. Only your `use`
statements move.

### Why

The package is published as `cleatsquad/php-llm-router`, but its root namespace
claimed `LlmRouter\` — a name no vendor owns. Any other library called
"llm router" collided with it, and a reader seeing `use LlmRouter\...` had no
way to tell which Composer package to install. PSR-4 expects
`Vendor\Package\`; this release makes the code say what the package name
already said.

### Migrating

```bash
composer require cleatsquad/php-llm-router:^4.0
```

Then rewrite your imports. This one pass covers every form — `use`, fully
qualified names, `::class`, string class names — and is safe to run twice,
because it skips what is already prefixed:

```bash
perl -pi -e 's/(?<!CleatSquad\\)LlmRouter(?=\\\\)/CleatSquad\\\\LlmRouter/g;
             s/(?<!CleatSquad\\)LlmRouter(?=\\(?!\\))/CleatSquad\\LlmRouter/g;' \
  $(grep -rl 'LlmRouter' src/ tests/ config/ 2>/dev/null)
```

The second pattern handles source code (`LlmRouter\Driver\ClaudeDriver`); the
first handles the escaped form found in JSON, YAML, NEON and PHPStan baselines
(`LlmRouter\\Driver\\ClaudeDriver`). Adjust the directory list to your tree.

Then check nothing was missed:

```bash
grep -rn '\bLlmRouter\\' --include='*.php' . | grep -v CleatSquad
```

### Two places worth checking by hand

- **Your own PHPStan/Psalm baselines** hold the old class names inside error
  messages. Regenerate them rather than editing them.
- **Container and configuration files** referencing drivers as strings
  (`'LlmRouter\Driver\ClaudeDriver'`) fail at runtime, not at compile time, so
  static analysis will not catch them. The `grep` above will.

### What does not change

Redis keys (`llm_router:*`), cache-entry shapes, breaker and quota state, wire
formats and the model catalogues are all identical. A mixed-version rollout is
safe: two builds differing only by namespace read each other's shared state
without a hiccup.

---

## 2.x → 3.0.0

**One breaking change, and it only affects `KimiDriver`.** Every other driver is
unchanged; if you don't use Kimi, `composer require` is the whole migration.

### KimiDriver refuses models outside its catalogue

It used to accept any model name and price it from a hardcoded guess. It now
carries a real catalogue — `kimi-k3`, `kimi-k2.7-code`, `kimi-k2.6`,
`kimi-k2.5` — priced in USD from Moonshot's published rates for the
international endpoint, and raises `UnknownModelException` for anything else.

```php
// Before — any name accepted, cost estimated from a guess
new KimiDriver($http, moonshotApiKey: $key);            // default moonshot-v1-8k

// After — international endpoint, catalogue model
new KimiDriver($http,
    moonshotUrl: 'https://api.moonshot.ai/v1',
    moonshotApiKey: $key,
    moonshotModel: 'kimi-k2.6',   // or omit: this is the default
);
```

**If you use the mainland endpoint (`api.moonshot.cn`)**, its `moonshot-v1-*`
models are deliberately absent: Moonshot publishes their rates in **yuan**, and
`CostEstimate` reports USD. Putting ¥ figures in the table would understate cost
by roughly a factor of seven. Register them with rates you have converted:

```php
new KimiDriver($http, moonshotApiKey: $key, extraModelPricing: [
    // ¥2 / ¥10 per million tokens, converted to USD per 1k at your own rate
    'moonshot-v1-8k' => ['input' => 0.00028, 'output' => 0.0014],
]);
```

Pick the conversion rate yourself and revisit it: this library will not invent
one, because a stale exchange rate baked into a pricing table produces a wrong
cost reported with total confidence.

## 2.0.x → 2.1.0

**Nothing is required.** Every addition is opt-in: omit `reasoningEffort` and
the requests this library sends are byte-identical to 2.0.x.

### Asking a model to think

```php
use CleatSquad\LlmRouter\Enum\ReasoningEffort;

$response = $driver->chat(new LLMRequest(
    messages: $messages,
    reasoningEffort: ReasoningEffort::High,
    includeReasoning: true,
));

$response->reasoning; // the trace, or null
```

Two things are worth knowing before you switch it on:

- **`supportsReasoning()` describes the driver, not your model.** Sending
  `reasoning_effort` to `gpt-4o` earns a 400 from OpenAI. Check that the model
  you named is a reasoning model — and remember that since 2.0.0 the shipped
  pricing tables only know a handful, so a reasoning model may need registering
  through `$extraModelPricing` first.
- **OpenAI never returns its trace.** `$reasoning` stays null there; you are
  paying for thinking tokens you cannot read. That is the provider's design,
  not a gap in this library.

### If you use tools across several turns, replay the trace

Anthropic, Mistral and Moonshot require the reasoning trace to come back on the
next turn. Moonshot documents that dropping it during a tool-calling loop
degrades the model, and nothing in the response reveals that it happened.

```php
// before — fine without reasoning, lossy with it
$messages[] = ['role' => 'assistant', 'content' => $response->content];

// after — carries the trace, and each driver re-emits it natively
$messages[] = $response->toMessage();
```

### Streaming

Reasoning does not arrive through the yielded values, so existing loops are
untouched. Opt in with a callback:

```php
$request = new LLMRequest(
    messages: $messages,
    reasoningEffort: ReasoningEffort::High,
    includeReasoning: true,
    onReasoning: fn (string $fragment) => $ui->showThinking($fragment),
);
```

### Cache keys changed shape

`CachingDriver` now folds the reasoning settings into its key, so entries
written by 2.0.x will not be hit after the upgrade. They expire on their own;
a one-off cold cache, nothing to do.

---

## 1.14.x → 2.0.0

**This major release carries exactly one breaking change.** Nothing else moved:
no interface, no signature, no constructor order, and the PHP floor stays at
8.2. If you never pass an explicit model name, `composer require` is the whole
migration.

### Unknown models are refused instead of silently substituted

**This is the one change that can break a working application, and it is worth
five minutes of your attention.**

Six drivers used to end model resolution like this:

```php
return isset(self::PRICING[$model]) ? $model : 'gpt-4o-mini';
```

Ask `OpenAiDriver` for `gpt-5` and you got an answer from `gpt-4o-mini` —
priced as `gpt-4o-mini`, reported as `gpt-4o-mini`, with nothing anywhere
saying a substitution had happened. You could run for months believing you were
on a frontier model.

It now throws `CleatSquad\LlmRouter\Exception\UnknownModelException`.

**Why this is likely to affect you:** the shipped pricing tables hold only 2–5
models each and lag behind the providers. Every model they predate used to
"work":

| Driver | Models it knows |
| --- | --- |
| `ClaudeDriver` | `claude-opus-4-8`, `claude-sonnet-5`, `claude-haiku-4-5` |
| `OpenAiDriver` | `gpt-4o`, `gpt-4o-mini` |
| `GeminiDriver` | `gemini-2.5-pro`, `gemini-2.5-flash`, `gemini-2.0-flash`, `gemini-1.5-flash`, `gemini-flash-lite-latest` |
| `DeepSeekDriver` | `deepseek-chat`, `deepseek-reasoner` |
| `GroqDriver` | `llama-3.3-70b-versatile`, `llama-3.1-8b-instant`, `gemma2-9b-it` |
| `MistralDriver` | `mistral-small-latest`, `mistral-large-latest`, `codestral-latest`, `open-mistral-nemo` |

Check what you actually pass:

```bash
grep -rn "model:" --include=*.php your-app/ | grep -i "gpt-\|claude-\|gemini-\|mistral-\|llama-\|deepseek-"
```

**If a model you use isn't listed**, register it with its pricing — one
argument, no fork, no waiting for a release:

```php
$driver = new OpenAiDriver($http, openAiApiKey: $key, extraModelPricing: [
    'gpt-5' => ['input' => 0.00125, 'output' => 0.01], // USD per 1k tokens
]);
```

The same argument corrects a stale shipped price; caller entries win over the
built-in table. Registered models also appear in `getModels()`.

**Unaffected:**

- Requests that pass no model at all. That is a caller declining to choose, and
  still resolves to the driver's default exactly as before.
- Provider-prefixed names: `anthropic/claude-sonnet-5` still resolves.
- `KimiDriver`, which has no pricing table and forwards the name as given.
- `OllamaDriver`, which resolves against the models actually installed on your
  local server.

**In a fail-over chain, this mostly resolves itself.**
`UnknownModelException` extends `RuntimeException`, so `FailoverDriver` treats
it as a failure and moves to the next candidate. A chain asking for `gpt-5`
will skip Claude and Gemini and land on the driver that knows it — which is the
behaviour you wanted all along, and what the silent substitution was hiding.

---

## 1.13.1 → 1.14.0

**No code changes are required.** No public interface, class name, method
signature or constructor argument order changed, and the PHP floor stays at
8.2. Every new constructor parameter is optional and appended last.

Two things need a decision rather than an edit: the Redis payload format
changed (§1), and the quota now enforces its ceiling correctly, which may
expose traffic that was previously slipping through (§2).

---

### 1. Redis entries written by 1.13.x are not readable by 1.14.0

Shared state was PHP-serialized. It is now JSON (breaker, cache) or plain
integers (quota), because `unserialize()` on a value read back from a shared
backend lets that value decide which PHP class gets instantiated — a
deserialization gadget primitive for anyone able to write to the instance. A
type check after the fact does not help: `__wakeup()`/`__destruct()` have
already run by then.

**What happens on deploy, without any action:**

| Store | Old entries | Effect |
| --- | --- | --- |
| `RedisCacheStore` | unreadable | Read as cache misses, overwritten on the next call. A one-off cold cache. |
| `RedisCircuitBreakerStore` | unreadable | Read as a closed breaker. A provider that was mid-cooldown gets one probe call before the breaker re-arms. |
| `RedisRateLimitStore` | different keys | Old keys are ignored and expire on their own. The current window restarts once. |

All three degrade safely, so **doing nothing is a valid choice.** If you would
rather not leave dead keys behind:

```bash
redis-cli --scan --pattern 'llm_router:*' | xargs -r redis-cli DEL
```

Run it during the deploy. Deleting the keys is equivalent to letting them
expire, just sooner.

**Mixed-version rollout** (some workers on 1.13.1, some on 1.14.0) is safe: the
old build reads new entries as absent and vice versa, so both degrade to a cold
cache and a closed breaker rather than to a wrong value. Neither build can be
made to deserialize the other's payload.

### 2. The quota now actually holds

`RateLimitedDriver` counted with a read-modify-write. Two workers reading the
same window before either wrote both stored `count + 1`, so increments were
lost and more traffic went out than the ceiling allowed.

With `RedisRateLimitStore` (or the new `ApcuRateLimitStore`), slots are now
reserved atomically. **If your configured limit was tuned against the leaky
behaviour, you will see `RuntimeException: Rate limit exceeded ...` where you
previously saw none** — that is the limit doing its job for the first time.

Check what you actually send before raising anything:

```php
$window = $store->getWindow('groq');
$window?->requestCount; // requests used in the current window
$window?->tokenCount;   // tokens used
```

Then either raise `maxRequestsPerMinute` to your real provider limit, or give
callers room to wait rather than fail:

```php
$driver = new RateLimitedDriver($inner, $store,
    maxRequestsPerMinute: 30,
    maxWaitSeconds: 5.0, // block up to 5s for a slot instead of throwing at once
);
```

Nothing changes for `InMemoryRateLimitStore`: it is process-local, so the race
never applied to it.

### 3. Behaviour changes worth knowing

None of these need action; they are visible in logs and metrics.

- **A 429 with `Retry-After` is now waited out properly.** `RetryingDriver`
  ignored the provider's delay and retried on its own ~0.5s backoff, earning a
  second 429. It now waits what the provider asked, capped by
  `maxDelaySeconds` (default 8s — raise it if your providers send long delays
  you want honoured). Expect fewer retries and longer individual calls.
- **`CircuitBreakerDriver` uses the provider's cooldown** when the failure that
  tripped it carried a `Retry-After`, instead of the configured `$openSeconds`.
  A circuit may now stay open substantially longer, which is the point.
- **A malformed `Retry-After` no longer means "retry immediately."** Values
  like `"1.5"` or `"+30"` were read as a delay of 0. They now read as "no
  usable value" and fall back to the jittered backoff.
- **Out-of-order streamed tool calls come back in index order.** If a provider
  opened tool call #1 before #0, the returned list previously followed arrival
  order, pairing each `id` with another call's arguments. If you worked around
  this by re-sorting downstream, that workaround is now redundant (and
  harmless).
- **A shared-store outage no longer breaks the call.** Cache and breaker fail
  open; the quota still fails closed on purpose. Pass a PSR-3 logger to see it:

  ```php
  new RedisCacheStore($redis, logger: $logger);
  ```

### 4. New, entirely optional

Running without Redis? The in-memory default is process-local, which under
PHP-FPM means one cache and one quota *per worker*. Two new options:

```php
// Cache and breaker over any PSR-16 backend (filesystem, APCu, Memcached, PDO)
use CleatSquad\LlmRouter\Cache\Psr16CacheStore;
use CleatSquad\LlmRouter\CircuitBreaker\Psr16CircuitBreakerStore;

$driver = new CachingDriver($driver, new Psr16CacheStore($psr16), ttlSeconds: 300);
$driver = new CircuitBreakerDriver($driver, new Psr16CircuitBreakerStore($psr16));

// Quota over APCu — atomic across the workers of one machine
use CleatSquad\LlmRouter\RateLimit\ApcuRateLimitStore;

$driver = new RateLimitedDriver($driver, new ApcuRateLimitStore(), maxRequestsPerMinute: 30);
```

There is no PSR-16 quota store: PSR-16 has no atomic increment, so one would be
a read-modify-write pretending to be a shared quota. `ApcuRateLimitStore` is
per-machine — divide your ceiling by your server count, or use Redis.

Writing your own store against a backend with a real atomic increment? Implement
`RateLimit\AtomicRateLimitStoreInterface`; `RateLimitedDriver` detects it and
takes the atomic path automatically.

### 5. Dependency change

`psr/simple-cache` ^3.0 is now required. It is an interface-only package with
no transitive dependencies. `composer update` handles it.

---

## Rollback

Downgrading to 1.13.1 is safe and needs no data migration — 1.13.1 reads
1.14.0's entries as absent and rewrites its own. You would be reinstating the
deserialization exposure and the leaky quota, so treat it as a short-term
measure.
