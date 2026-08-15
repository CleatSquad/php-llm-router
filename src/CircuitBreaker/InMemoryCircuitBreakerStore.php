<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\CircuitBreaker;

/**
 * Default CircuitBreakerStoreInterface: a same-process array, fine for a CLI session or single worker.
 * Use RedisCircuitBreakerStore instead when breaker state needs to be shared across requests or processes.
 */
final class InMemoryCircuitBreakerStore implements CircuitBreakerStoreInterface
{
    /** @var array<string, CircuitBreakerState> */
    private array $states = [];

    public function getState(string $driverId): CircuitBreakerState
    {
        return $this->states[$driverId] ?? new CircuitBreakerState();
    }

    public function saveState(string $driverId, CircuitBreakerState $state): void
    {
        $this->states[$driverId] = $state;
    }
}
