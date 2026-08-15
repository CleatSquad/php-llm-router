<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Routing;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\Contract\RoutingStrategyInterface;
use CleatSquad\LlmRouter\Contract\Routing\ActiveRequestsTrackerInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use RuntimeException;

final class LeastBusyStrategy implements RoutingStrategyInterface
{
    public function __construct(
        private readonly ActiveRequestsTrackerInterface $tracker
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
        $minActive = PHP_INT_MAX;

        foreach ($available as $driver) {
            $active = $this->tracker->getActiveRequests($driver->getId());
            if ($active < $minActive) {
                $minActive = $active;
                $bestDriver = $driver;
            }
        }

        return $bestDriver ?? $available[0];
    }
}
