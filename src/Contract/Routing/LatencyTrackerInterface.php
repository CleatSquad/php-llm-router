<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Contract\Routing;

interface LatencyTrackerInterface
{
    /**
     * Get the recorded average or estimated latency in milliseconds for a driver.
     * Returns null if no latency data has been recorded yet.
     */
    public function getLatencyMs(string $driverId): ?float;

    /**
     * Record a completed request execution duration in milliseconds.
     */
    public function recordLatencyMs(string $driverId, float $latencyMs): void;
}
