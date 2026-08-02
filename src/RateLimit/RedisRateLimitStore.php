<?php

declare(strict_types=1);

namespace LlmRouter\RateLimit;

use Redis;

/**
 * RateLimitStoreInterface backed by Redis, sharing the usage window across requests and workers.
 * Keep $ttlSeconds >= RateLimitedDriver's $windowSeconds, or a mid-window expiry silently starts a fresh window early (more permissive, never more restrictive, but less exact).
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
