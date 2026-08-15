<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Routing;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\Contract\RoutingStrategyInterface;
use CleatSquad\LlmRouter\Contract\Routing\UsageTrackerInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use RuntimeException;

final class UsageStrategy implements RoutingStrategyInterface
{
    public function __construct(
        private readonly UsageTrackerInterface $tracker
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
        $minUsage = PHP_INT_MAX;

        foreach ($available as $driver) {
            $usage = $this->tracker->getUsage($driver->getId());
            if ($usage < $minUsage) {
                $minUsage = $usage;
                $bestDriver = $driver;
            }
        }

        return $bestDriver ?? $available[0];
    }
}
