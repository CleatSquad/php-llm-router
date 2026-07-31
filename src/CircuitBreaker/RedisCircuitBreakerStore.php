<?php

declare(strict_types=1);

namespace LlmRouter\CircuitBreaker;

use Redis;

/**
 * CircuitBreakerStoreInterface backed by Redis (ext-redis), so a
 * driver's failure count and open-until timestamp are shared across
 * requests and worker processes instead of living only in one process
 * like InMemoryCircuitBreakerStore — the whole point of a circuit
 * breaker guarding a shared downstream provider.
 *
 * Keys carry $ttlSeconds themselves (default 1h), refreshed on every
 * write, so a driver nobody has called in a while self-cleans instead
 * of leaving orphaned state in Redis forever. Keep $ttlSeconds
 * comfortably larger than CircuitBreakerDriver's $openSeconds — if the
 * key expired while the breaker was still meant to be open, the next
 * read would come back closed instead.
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
