<?php

declare(strict_types=1);

namespace LlmRouter\CircuitBreaker;

use LlmRouter\Serialization\CircuitBreakerStateCodec;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * CircuitBreakerStoreInterface on top of any PSR-16 cache, for deployments
 * without Redis (APCu, filesystem, Memcached, ... via Symfony Cache, Laravel,
 * or any other PSR-16 implementation).
 *
 * The breaker tolerates a store that is only shared per-machine: the worst a
 * partially-shared view costs is that one machine's workers rediscover an
 * outage another machine already knows about.
 *
 * Encoding: JSON via CircuitBreakerStateCodec, never PHP serialization — see
 * that class for why a value read back from a shared backend must not be able
 * to choose which class gets instantiated.
 *
 * Failure semantics: fail-open, identical to RedisCircuitBreakerStore. An
 * unreachable backend or an unusable value reads as "closed" and the call is
 * attempted, because the breaker exists to spare callers a doomed call, not to
 * authorise them — failing closed would turn a cache blip into a total outage.
 */
final class Psr16CircuitBreakerStore implements CircuitBreakerStoreInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix = 'llm_router.circuit_breaker.',
        private readonly int $ttlSeconds = 3600,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function getState(string $driverId): CircuitBreakerState
    {
        try {
            /** @var mixed $raw */
            $raw = $this->cache->get($this->key($driverId));
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
            $this->cache->set($this->key($driverId), CircuitBreakerStateCodec::encode($state), $this->ttlSeconds);
        } catch (Throwable $e) {
            $this->logger?->warning('llm_router.circuit_breaker.store_unavailable', [
                'operation' => 'set',
                'driver_id' => $driverId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function key(string $driverId): string
    {
        return str_replace(['{', '}', '(', ')', '/', '\\', '@', ':'], '_', $this->prefix . $driverId);
    }
}
