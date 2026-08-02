<?php

declare(strict_types=1);

namespace LlmRouter\CircuitBreaker;

use Redis;

/**
 * CircuitBreakerStoreInterface backed by Redis, sharing failure state across requests and workers.
 * Keys self-expire after $ttlSeconds (refreshed on write); keep it comfortably larger than $openSeconds, or the breaker can read as closed before it should.
 */
final class RedisCircuitBreakerStore implements CircuitBreakerStoreInterface
{
    public function __construct(
        private readonly Redis $redis,
        private readonly string $prefix = 'llm_router:circuit_breaker:',
        private readonly int $ttlSeconds = 3600,
    ) {}

    public function getState(string $driverId): CircuitBreakerState
    {
        $raw = $this->redis->get($this->prefix . $driverId);
        if ($raw === false) {
            return new CircuitBreakerState();
        }

        $state = @unserialize($raw);
        return $state instanceof CircuitBreakerState ? $state : new CircuitBreakerState();
    }

    public function saveState(string $driverId, CircuitBreakerState $state): void
    {
        $this->redis->setex($this->prefix . $driverId, $this->ttlSeconds, serialize($state));
    }
}
