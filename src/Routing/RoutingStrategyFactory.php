<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Routing;

use CleatSquad\LlmRouter\Adapter\RoutingPolicyAdapter;
use CleatSquad\LlmRouter\Constraint\CapabilityConstraint;
use CleatSquad\LlmRouter\Constraint\ContextWindowConstraint;
use CleatSquad\LlmRouter\Contract\RoutingStrategyInterface;
use CleatSquad\LlmRouter\Contract\Routing\RoutingStrategyFactoryInterface;
use CleatSquad\LlmRouter\Contract\Routing\ActiveRequestsTrackerInterface;
use CleatSquad\LlmRouter\Contract\Routing\LatencyTrackerInterface;
use CleatSquad\LlmRouter\Contract\Routing\RandomizerInterface;
use CleatSquad\LlmRouter\Policy\RoutingPolicy;
use CleatSquad\LlmRouter\Ranker\CostRanker;
use CleatSquad\LlmRouter\Ranker\LatencyRanker;
use CleatSquad\LlmRouter\Ranker\PriorityRanker;
use CleatSquad\LlmRouter\Ranker\ReliabilityRanker;
use CleatSquad\LlmRouter\Selector\BestCandidateSelector;
use CleatSquad\LlmRouter\Selector\RoundRobinSelector;
use CleatSquad\LlmRouter\Selector\WeightedSelector;
use InvalidArgumentException;

final class RoutingStrategyFactory implements RoutingStrategyFactoryInterface
{
    public function __construct(
        private readonly ?ActiveRequestsTrackerInterface $activeRequestsTracker = null,
        private readonly ?LatencyTrackerInterface $latencyTracker = null,
        private readonly ?RandomizerInterface $randomizer = null,
    ) {}

    public function getActiveRequestsTracker(): ?ActiveRequestsTrackerInterface
    {
        return $this->activeRequestsTracker;
    }

    public function create(string $name, array $options = []): RoutingStrategyInterface
    {
        $policy = match (strtolower($name)) {
            'priority' => new RoutingPolicy(
                rankers: [new PriorityRanker(
                    priorities: is_array($options['priorities'] ?? null) ? $options['priorities'] : [],
                    qualityPriorities: is_array($options['quality_priorities'] ?? null) ? $options['quality_priorities'] : []
                )],
                name: 'priority'
            ),
            'weighted' => new RoutingPolicy(
                selector: new WeightedSelector(
                    weights: is_array($options['weights'] ?? null) ? $options['weights'] : [],
                    randomizer: $this->randomizer
                ),
                name: 'weighted'
            ),
            'cost' => new RoutingPolicy(
                rankers: [new CostRanker()],
                name: 'cost'
            ),
            'capability', 'capabilities' => new RoutingPolicy(
                constraints: [new CapabilityConstraint(
                    requireTools: (bool) ($options['require_tools'] ?? false),
                    requireVision: (bool) ($options['require_vision'] ?? false),
                    requireReasoning: (bool) ($options['require_reasoning'] ?? false),
                    requireStreaming: (bool) ($options['require_streaming'] ?? false)
                )],
                name: 'capability'
            ),
            'context-window', 'context_window', 'capacity' => new RoutingPolicy(
                constraints: [new ContextWindowConstraint(
                    maxContextTokens: is_array($options['max_context_tokens'] ?? null) ? $options['max_context_tokens'] : []
                )],
                name: 'context-window'
            ),
            'round-robin', 'roundrobin', 'round_robin' => new RoutingPolicy(
                selector: new RoundRobinSelector(),
                name: 'round-robin'
            ),
            'random' => new RoutingPolicy(
                selector: new WeightedSelector(randomizer: $this->randomizer),
                name: 'random'
            ),
            'latency' => new RoutingPolicy(
                rankers: [new LatencyRanker(
                    tracker: $this->latencyTracker,
                    defaultLatencyMs: (float) ($options['default_latency_ms'] ?? 100.0)
                )],
                name: 'latency'
            ),
            'reliability' => new RoutingPolicy(
                rankers: [new ReliabilityRanker()],
                name: 'reliability'
            ),
            'quota' => new RoutingPolicy(
                constraints: [new \CleatSquad\LlmRouter\Constraint\QuotaConstraint()],
                name: 'quota'
            ),
            'least-busy', 'leastbusy', 'least_busy' => new RoutingPolicy(
                rankers: [new PriorityRanker()],
                name: 'least-busy'
            ),
            'usage', 'usage-based', 'usage_based' => new RoutingPolicy(
                rankers: [new PriorityRanker()],
                name: 'usage'
            ),
            default => throw new InvalidArgumentException("Unknown routing strategy: '$name'."),
        };

        return new RoutingPolicyAdapter($policy);
    }
}
