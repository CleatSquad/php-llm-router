<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\CircuitBreaker;

use CleatSquad\LlmRouter\Serialization\CircuitBreakerStateCodec;
use Psr\Log\LoggerInterface;
use Redis;
use Throwable;

/**
 * CircuitBreakerStoreInterface backed by Redis, sharing failure state across requests and workers.
 * Keys self-expire after $ttlSeconds (refreshed on write); keep it comfortably larger than $openSeconds, or the breaker can read as closed before it should.
 *
 * Encoding: JSON — a failure count and a Unix timestamp — rebuilt field by field into a
 * CircuitBreakerState. The store never calls unserialize(), so a tampered-with value cannot
 * drive class instantiation; it can only fail validation.
 *
 * Failure semantics: fail-open. When Redis is unreachable or the stored value is unusable, the
 * breaker reads as closed and the LLM call is attempted. The breaker exists to spare callers a
 * call that is likely to fail, not to authorise them — losing it costs latency on an outage,
 * whereas failing closed would turn a Redis blip into a total outage of every provider at once.
 * Failed writes are dropped the same way. Both are logged at warning level when a logger is given.
 */
final class RedisCircuitBreakerStore implements CircuitBreakerStoreInterface
{
    public function __construct(
        private readonly Redis $redis,
        private readonly string $prefix = 'llm_router:circuit_breaker:',
        private readonly int $ttlSeconds = 3600,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function getState(string $driverId): CircuitBreakerState
    {
        try {
            $raw = $this->redis->get($this->prefix . $driverId);
        } catch (Throwable $e) {
            $this->logger?->warning('llm_router.circuit_breaker.store_unavailable', [
                'operation' => 'get',
                'driver_id' => $driverId,
                'error' => $e->getMessage(),
            ]);
            return new CircuitBreakerState();
        }

        if (!is_string($raw)) {
            return new CircuitBreakerState();
        }

        try {
            return CircuitBreakerStateCodec::decode($raw) ?? new CircuitBreakerState();
        } catch (Throwable $e) {
            $this->logger?->warning('llm_router.circuit_breaker.corrupt_entry', [
                'driver_id' => $driverId,
                'error' => $e->getMessage(),
            ]);
            return new CircuitBreakerState();
        }
    }

    public function saveState(string $driverId, CircuitBreakerState $state): void
    {
        try {
            $this->redis->setex(
                $this->prefix . $driverId,
                $this->ttlSeconds,
                CircuitBreakerStateCodec::encode($state)
            );
        } catch (Throwable $e) {
            $this->logger?->warning('llm_router.circuit_breaker.store_unavailable', [
                'operation' => 'set',
                'driver_id' => $driverId,
                'error' => $e->getMessage(),
            ]);
        }
    }

}
