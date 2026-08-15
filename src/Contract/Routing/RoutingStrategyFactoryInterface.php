<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Contract\Routing;

use CleatSquad\LlmRouter\Contract\RoutingStrategyInterface;

interface RoutingStrategyFactoryInterface
{
    /**
     * Create a RoutingStrategyInterface by name (e.g. 'priority', 'weighted', 'random', 'least-busy', 'latency', 'cost', 'round-robin').
     *
     * @param string $name
     * @param array<string, mixed> $options
     * @return RoutingStrategyInterface
     */
    public function create(string $name, array $options = []): RoutingStrategyInterface;
}
