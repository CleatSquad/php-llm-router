<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Routing;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\Contract\RoutingStrategyInterface;
use CleatSquad\LlmRouter\Contract\Routing\RandomizerInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use InvalidArgumentException;
use RuntimeException;

final class WeightedStrategy implements RoutingStrategyInterface
{
    private readonly RandomizerInterface $randomizer;

    /**
     * @param array<string, int> $weights Driver ID -> positive integer weight
     */
    public function __construct(
        private readonly array $weights = [],
        ?RandomizerInterface $randomizer = null
    ) {
        foreach ($this->weights as $id => $weight) {
            if ($weight < 0) {
                throw new InvalidArgumentException("Weight for driver '$id' must be non-negative.");
            }
        }
        $this->randomizer = $randomizer ?? new NativeRandomizer();
    }

    public function select(LLMRequest $request, array $drivers): LLMDriverInterface
    {
        if (empty($drivers)) {
            throw new RuntimeException('No LLM drivers provided to the routing strategy.');
        }

        $available = array_values(array_filter($drivers, static fn (LLMDriverInterface $d) => $d->isAvailable()));

        if (empty($available)) {
            throw new RuntimeException('All configured LLM drivers are currently unavailable.');
        }

        $totalWeight = 0;
        $weightedAvailable = [];

        foreach ($available as $driver) {
            $weight = max(1, $this->weights[$driver->getId()] ?? 1);
            $totalWeight += $weight;
            $weightedAvailable[] = [
                'driver' => $driver,
                'weight' => $weight,
            ];
        }

        $random = $this->randomizer->nextInt(1, $totalWeight);
        $current = 0;

        foreach ($weightedAvailable as $item) {
            $current += $item['weight'];
            if ($random <= $current) {
                return $item['driver'];
            }
        }

        return $weightedAvailable[0]['driver'];
    }
}
