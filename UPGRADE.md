# Upgrade guide

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
use LlmRouter\Cache\Psr16CacheStore;
use LlmRouter\CircuitBreaker\Psr16CircuitBreakerStore;

$driver = new CachingDriver($driver, new Psr16CacheStore($psr16), ttlSeconds: 300);
$driver = new CircuitBreakerDriver($driver, new Psr16CircuitBreakerStore($psr16));

// Quota over APCu — atomic across the workers of one machine
use LlmRouter\RateLimit\ApcuRateLimitStore;

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
