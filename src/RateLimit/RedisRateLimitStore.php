<?php

declare(strict_types=1);

namespace LlmRouter\RateLimit;

use Redis;

/**
 * RateLimitStoreInterface backed by Redis (ext-redis), so a driver's
 * RPM/TPM usage window is shared across requests and worker processes
 * instead of living only in one process like InMemoryRateLimitStore —
 * needed for the budget to mean anything once more than one PHP-FPM
 * worker (or one machine) is calling the same driver.
 *
 * Keep $ttlSeconds >= the RateLimitedDriver's $windowSeconds: if the
 * key expired mid-window, the next read starts a fresh window early
 * and under-counts usage — the budget becomes more permissive, never
 * more restrictive, but it does stop being exact.
 */
final class RedisRateLimitStore implements RateLimitStoreInterface
{
    public function __construct(
        private readonly Redis $redis,
        private readonly string $prefix = 'llm_router:rate_limit:',
        private readonly int $ttlSeconds = 120,
    ) {}

    public function getWindow(string $driverId): ?RateLimitWindow
    {
        $raw = $this->redis->get($this->prefix . $driverId);
        if ($raw === false) {
            return null;
        }

        $window = @unserialize($raw);
        return $window instanceof RateLimitWindow ? $window : null;
    }

    public function saveWindow(string $driverId, RateLimitWindow $window): void
    {
        $this->redis->setex($this->prefix . $driverId, $this->ttlSeconds, serialize($window));
    }
}
