<?php

declare(strict_types=1);

namespace LlmRouter\CircuitBreaker;

/**
 * Default CircuitBreakerStoreInterface: plain array, lives only for
 * the current process. Fine for a CLI session or a single long-lived
 * worker; a multi-process web app wanting shared breaker state across
 * requests should implement the interface against Redis/DB instead.
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
