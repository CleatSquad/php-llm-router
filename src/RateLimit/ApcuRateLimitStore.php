<?php

declare(strict_types=1);

namespace LlmRouter\RateLimit;

use DateTimeImmutable;
use RuntimeException;

/**
 * Atomic rate-limit store for deployments without Redis, backed by APCu.
 *
 * apcu_inc() is atomic across the PHP-FPM workers of one machine, which is
 * what makes this a valid AtomicRateLimitStoreInterface: two workers racing on
 * the same window still produce two increments, never one. It is the only
 * Redis-free backend in this package that can honestly claim that — PSR-16 has
 * no atomic increment, so a PSR-16 quota would be a read-modify-write wearing
 * a shared-store costume, and this package deliberately does not ship one.
 *
 * Scope: one machine. APCu memory is per-server, so a quota enforced with this
 * store is per-server too — with four app servers and a limit of 30/min, the
 * provider sees up to 120/min. Divide the ceiling by your server count, or use
 * RedisRateLimitStore when the quota must hold across the fleet.
 *
 * Failure semantics: fail-closed, like RedisRateLimitStore. A missing or
 * disabled APCu extension is a configuration error and is reported as one at
 * construction, rather than degrading into an unlimited quota nobody notices.
 *
 * Counters are integers under keys derived from the fixed window index, so
 * nothing here is deserialized and a window rolls over by addressing a new key.
 */
final class ApcuRateLimitStore implements AtomicRateLimitStoreInterface
{
    /**
     * @param int $windowSeconds Fixed-window length used by getWindow()/saveWindow();
     *   keep it equal to RateLimitedDriver's own $windowSeconds.
     */
    public function __construct(
        private readonly string $prefix = 'llm_router.rate_limit.',
        private readonly int $windowSeconds = 60,
    ) {
        if (!function_exists('apcu_inc') || !apcu_enabled()) {
            throw new RuntimeException(
                'ApcuRateLimitStore requires the APCu extension to be installed and enabled '
                . '(apc.enabled=1, and apc.enable_cli=1 when running under the CLI SAPI).'
            );
        }
    }

    public function getWindow(string $driverId): ?RateLimitWindow
    {
        $requests = apcu_fetch($this->key($driverId, 'requests', $this->windowSeconds), $found);
        if ($found !== true) {
            return null;
        }

        return new RateLimitWindow(
            $this->windowStart($this->windowSeconds),
            (int) $requests,
            (int) apcu_fetch($this->key($driverId, 'tokens', $this->windowSeconds)),
        );
    }

    public function saveWindow(string $driverId, RateLimitWindow $window): void
    {
        $ttl = $this->ttlFor($this->windowSeconds);
        apcu_store($this->key($driverId, 'requests', $this->windowSeconds), $window->requestCount, $ttl);
        apcu_store($this->key($driverId, 'tokens', $this->windowSeconds), $window->tokenCount, $ttl);
    }

    public function tryAcquire(
        string $driverId,
        int $windowSeconds,
        ?int $maxRequests,
        ?int $maxTokens,
    ): ?RateLimitWindow {
        $tokenKey = $this->key($driverId, 'tokens', $windowSeconds);

        if ($maxTokens !== null && (int) apcu_fetch($tokenKey) >= $maxTokens) {
            return null;
        }

        $requests = $this->increment($this->key($driverId, 'requests', $windowSeconds), 1, $windowSeconds);

        if ($maxRequests !== null && $requests > $maxRequests) {
            // Hand the slot back: a refused attempt must not consume quota for
            // the rest of the window. Incrementing first and compensating is
            // what keeps this free of the lost-update race.
            $this->increment($this->key($driverId, 'requests', $windowSeconds), -1, $windowSeconds);
            return null;
        }

        return new RateLimitWindow(
            $this->windowStart($windowSeconds),
            $requests,
            (int) apcu_fetch($tokenKey),
        );
    }

    public function addTokens(string $driverId, int $windowSeconds, int $tokens): void
    {
        if ($tokens === 0) {
            return;
        }

        $this->increment($this->key($driverId, 'tokens', $windowSeconds), $tokens, $windowSeconds);
    }

    /**
     * apcu_inc() only increments an existing key, so the counter is seeded
     * with apcu_add() — itself atomic, and a no-op when another worker won the
     * race to create it, in which case the increment simply applies to theirs.
     */
    private function increment(string $key, int $by, int $windowSeconds): int
    {
        apcu_add($key, 0, $this->ttlFor($windowSeconds));

        $value = apcu_inc($key, $by, $ok);

        if ($ok !== true || !is_int($value)) {
            throw new RuntimeException(sprintf('APCu refused to increment rate-limit counter "%s".', $key));
        }

        return $value;
    }

    private function key(string $driverId, string $field, int $windowSeconds): string
    {
        return $this->prefix . $driverId . '.' . $field . '.' . $this->windowIndex($windowSeconds);
    }

    private function windowIndex(int $windowSeconds): int
    {
        return $windowSeconds > 0 ? intdiv(time(), $windowSeconds) : time();
    }

    private function windowStart(int $windowSeconds): DateTimeImmutable
    {
        $start = $windowSeconds > 0 ? $this->windowIndex($windowSeconds) * $windowSeconds : time();

        return (new DateTimeImmutable())->setTimestamp($start);
    }

    private function ttlFor(int $windowSeconds): int
    {
        return max($windowSeconds * 2, 1);
    }
}
