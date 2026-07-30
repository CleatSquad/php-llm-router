<?php

declare(strict_types=1);

namespace LlmRouter\CircuitBreaker;

/**
 * Where CircuitBreakerDriver persists its per-driver failure state.
 * The default is a same-process array (InMemoryCircuitBreakerStore);
 * implement this against Redis/DB/etc. to share breaker state across
 * requests or worker processes.
 */
interface CircuitBreakerStoreInterface
{
    public function getState(string $driverId): CircuitBreakerState;

    public function saveState(string $driverId, CircuitBreakerState $state): void;
}
