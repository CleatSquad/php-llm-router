<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Routing;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\Contract\RoutingStrategyInterface;
use CleatSquad\LlmRouter\Contract\Routing\RandomizerInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use RuntimeException;

final class RandomStrategy implements RoutingStrategyInterface
{
    private readonly RandomizerInterface $randomizer;

    public function __construct(
        ?RandomizerInterface $randomizer = null
    ) {
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

        $index = $this->randomizer->nextInt(0, count($available) - 1);

        return $available[$index];
    }
}
