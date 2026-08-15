<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Routing;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\Contract\RoutingStrategyInterface;
use CleatSquad\LlmRouter\Contract\Routing\ReliabilityTrackerInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use RuntimeException;

final class ReliabilityStrategy implements RoutingStrategyInterface
{
    /**
     * @param ReliabilityTrackerInterface $tracker Tracker recording success and failure statistics
     * @param float $defaultSuccessRate Fallback score (0.0 to 1.0) for candidates without enough sample data
     */
    public function __construct(
        private readonly ReliabilityTrackerInterface $tracker,
        private readonly float $defaultSuccessRate = 1.0
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
        $maxRate = -1.0;

        foreach ($available as $driver) {
            $rate = $this->tracker->getSuccessRate($driver->getId()) ?? $this->defaultSuccessRate;
            if ($rate > $maxRate) {
                $maxRate = $rate;
                $bestDriver = $driver;
            }
        }

        return $bestDriver ?? $available[0];
    }
}
