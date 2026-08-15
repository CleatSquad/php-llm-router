<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Routing;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\Contract\RoutingStrategyInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use RuntimeException;

final class CompositeStrategy implements RoutingStrategyInterface
{
    /**
     * @param RoutingStrategyInterface[] $strategies Ordered list of strategies (e.g. constraints then ranking)
     */
    public function __construct(
        private readonly array $strategies
    ) {
        if (empty($this->strategies)) {
            throw new \InvalidArgumentException('CompositeStrategy requires at least one routing strategy.');
        }
    }

    public function select(LLMRequest $request, array $drivers): LLMDriverInterface
    {
        if (empty($drivers)) {
            throw new RuntimeException('No LLM drivers provided to the routing strategy.');
        }

        $candidates = $drivers;

        foreach ($this->strategies as $strategy) {
            $selected = $strategy->select($request, $candidates);
            // If selecting reduces/filters candidates, narrow down candidates list
            $candidates = [$selected];
        }

        return $candidates[0];
    }
}
