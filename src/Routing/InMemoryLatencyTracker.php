<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Routing;

use CleatSquad\LlmRouter\Contract\Routing\LatencyTrackerInterface;

final class InMemoryLatencyTracker implements LatencyTrackerInterface
{
    /** @var array<string, float> Exponential Moving Average (EMA) of latency in ms */
    private array $latencies = [];

    /**
     * @param float $alpha Smoothing factor for EMA (between 0.0 and 1.0)
     */
    public function __construct(
        private readonly float $alpha = 0.2
    ) {}

    public function getLatencyMs(string $driverId): ?float
    {
        return $this->latencies[$driverId] ?? null;
    }

    public function recordLatencyMs(string $driverId, float $latencyMs): void
    {
        if ($latencyMs < 0) {
            return;
        }

        if (!isset($this->latencies[$driverId])) {
            $this->latencies[$driverId] = $latencyMs;
            return;
        }

        // Exponential Moving Average
        $this->latencies[$driverId] = ($this->alpha * $latencyMs) + ((1 - $this->alpha) * $this->latencies[$driverId]);
    }
}
