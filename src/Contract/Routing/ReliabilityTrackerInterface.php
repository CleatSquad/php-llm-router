<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Contract\Routing;

interface ReliabilityTrackerInterface
{
    /**
     * Get success rate for a driver ID as a float between 0.0 (0%) and 1.0 (100%).
     * Returns null if minimum sample count is not met.
     */
    public function getSuccessRate(string $driverId): ?float;

    /**
     * Record a successful request completion.
     */
    public function recordSuccess(string $driverId): void;

    /**
     * Record a request failure (error, timeout, 429 rate limit, etc.).
     */
    public function recordFailure(string $driverId): void;
}
