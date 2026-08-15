<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Routing;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\Contract\RoutingStrategyInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use RuntimeException;

final class CostStrategy implements RoutingStrategyInterface
{
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
        $minCost = INF;

        foreach ($available as $driver) {
            $estimate = $driver->estimateCost($request);
            if ($estimate->estimatedCostUsd < $minCost) {
                $minCost = $estimate->estimatedCostUsd;
                $bestDriver = $driver;
            }
        }

        return $bestDriver ?? $available[0];
    }
}
