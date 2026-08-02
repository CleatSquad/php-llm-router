<?php

declare(strict_types=1);

namespace LlmRouter\CircuitBreaker;

/**
 * Where CircuitBreakerDriver persists per-driver failure state; implement against Redis/DB to share it across processes.
 */
interface CircuitBreakerStoreInterface
{
    public function getState(string $driverId): CircuitBreakerState;

    public function saveState(string $driverId, CircuitBreakerState $state): void;
}
