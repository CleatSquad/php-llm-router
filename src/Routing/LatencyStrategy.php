<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Routing;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\Contract\RoutingStrategyInterface;
use CleatSquad\LlmRouter\Contract\Routing\LatencyTrackerInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use RuntimeException;

final class LatencyStrategy implements RoutingStrategyInterface
{
    /**
     * @param LatencyTrackerInterface $tracker Tracker recording driver latencies in ms
     * @param float $defaultLatencyMs Assumed latency for candidates without history (warm-up)
     */
    public function __construct(
        private readonly LatencyTrackerInterface $tracker,
        private readonly float $defaultLatencyMs = 0.0
    ) {}

    public function select(LLMRequest $request, array $drivers): LLMDriverInterface
    {
        if (empty($drivers)) {
            throw new RuntimeException('No LLM drivers provided to the routing strategy.');
        }

        $available = array_values(array_filter($drivers, static fn (LLMDriverInterface $d) => $d->isAvailable()));

        if (empty($available)) {
            throw new RuntimeException('All configured LLM drivers are currently unavailable.');
        }

        $bestDriver = null;
        $minLatency = INF;

        foreach ($available as $driver) {
            $latency = $this->tracker->getLatencyMs($driver->getId()) ?? $this->defaultLatencyMs;
            if ($latency < $minLatency) {
                $minLatency = $latency;
                $bestDriver = $driver;
            }
        }

        return $bestDriver ?? $available[0];
    }
}
