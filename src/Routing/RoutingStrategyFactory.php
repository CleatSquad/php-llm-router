<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Routing;

use CleatSquad\LlmRouter\Contract\RoutingStrategyInterface;
use CleatSquad\LlmRouter\Contract\Routing\RoutingStrategyFactoryInterface;
use CleatSquad\LlmRouter\Contract\Routing\ActiveRequestsTrackerInterface;
use CleatSquad\LlmRouter\Contract\Routing\LatencyTrackerInterface;
use CleatSquad\LlmRouter\Contract\Routing\RandomizerInterface;
use InvalidArgumentException;

final class RoutingStrategyFactory implements RoutingStrategyFactoryInterface
{
    public function __construct(
        private readonly ?ActiveRequestsTrackerInterface $activeRequestsTracker = null,
        private readonly ?LatencyTrackerInterface $latencyTracker = null,
        private readonly ?RandomizerInterface $randomizer = null,
    ) {}

    public function create(string $name, array $options = []): RoutingStrategyInterface
    {
        return match (strtolower($name)) {
            'priority' => new PriorityStrategy(
                priorities: is_array($options['priorities'] ?? null) ? $options['priorities'] : [],
                qualityPriorities: is_array($options['quality_priorities'] ?? null) ? $options['quality_priorities'] : []
            ),
            'weighted' => new WeightedStrategy(
                weights: is_array($options['weights'] ?? null) ? $options['weights'] : [],
                randomizer: $this->randomizer
            ),
            'random' => new RandomStrategy(
                randomizer: $this->randomizer
            ),
            'least-busy', 'leastbusy', 'least_busy' => new LeastBusyStrategy(
                tracker: $this->activeRequestsTracker ?? new InMemoryActiveRequestsTracker()
            ),
            'latency' => new LatencyStrategy(
                tracker: $this->latencyTracker ?? new InMemoryLatencyTracker(),
                defaultLatencyMs: (float) ($options['default_latency_ms'] ?? 0.0)
            ),
            'cost' => new CostStrategy(),
            'usage', 'usage-based', 'usage_based' => new UsageStrategy(
                tracker: new InMemoryUsageTracker()
            ),
            'context-window', 'context_window', 'capacity' => new ContextWindowStrategy(
                maxContextTokens: is_array($options['max_context_tokens'] ?? null) ? $options['max_context_tokens'] : []
            ),
            'round-robin', 'roundrobin', 'round_robin' => new RoundRobinStrategy(
                weights: is_array($options['weights'] ?? null) ? $options['weights'] : []
            ),
            default => throw new InvalidArgumentException("Unknown routing strategy: '$name'."),
        };
    }
}
