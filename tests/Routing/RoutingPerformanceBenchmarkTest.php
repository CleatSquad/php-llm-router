<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Routing;

use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Routing\CapabilityStrategy;
use CleatSquad\LlmRouter\Routing\CompositeStrategy;
use CleatSquad\LlmRouter\Routing\ContextWindowStrategy;
use CleatSquad\LlmRouter\Routing\CostStrategy;
use CleatSquad\LlmRouter\Routing\InMemoryActiveRequestsTracker;
use CleatSquad\LlmRouter\Routing\InMemoryLatencyTracker;
use CleatSquad\LlmRouter\Routing\InMemoryUsageTracker;
use CleatSquad\LlmRouter\Routing\LatencyStrategy;
use CleatSquad\LlmRouter\Routing\LeastBusyStrategy;
use CleatSquad\LlmRouter\Routing\PriorityStrategy;
use CleatSquad\LlmRouter\Routing\RandomStrategy;
use CleatSquad\LlmRouter\Routing\RoundRobinStrategy;
use CleatSquad\LlmRouter\Routing\UsageStrategy;
use CleatSquad\LlmRouter\Routing\WeightedStrategy;
use CleatSquad\LlmRouter\Tests\Fixtures\FakeDriver;
use PHPUnit\Framework\TestCase;

final class RoutingPerformanceBenchmarkTest extends TestCase
{
    public function testRoutingStrategiesExecutionTimeIsNegligible(): void
    {
        $request = new LLMRequest(messages: [['role' => 'user', 'content' => 'Performance benchmark request']]);

        $drivers = [];
        for ($i = 0; $i < 20; $i++) {
            $drivers[] = new FakeDriver("driver-$i", available: true, supportsTools: true);
        }

        $strategies = [
            'priority' => new PriorityStrategy(),
            'random' => new RandomStrategy(),
            'weighted' => new WeightedStrategy(),
            'round-robin' => new RoundRobinStrategy(),
            'least-busy' => new LeastBusyStrategy(new InMemoryActiveRequestsTracker()),
            'usage' => new UsageStrategy(new InMemoryUsageTracker()),
            'latency' => new LatencyStrategy(new InMemoryLatencyTracker()),
            'cost' => new CostStrategy(),
            'context-window' => new ContextWindowStrategy(),
            'capability' => new CapabilityStrategy(requireTools: true),
            'composite' => new CompositeStrategy([
                new CapabilityStrategy(requireTools: true),
                new PriorityStrategy(),
            ]),
        ];

        foreach ($strategies as $name => $strategy) {
            $start = microtime(true);
            for ($k = 0; $k < 1000; $k++) {
                $strategy->select($request, $drivers);
            }
            $durationMs = (microtime(true) - $start) * 1000;

            // 1000 routing selections across 20 drivers must execute in < 250ms (generous upper bound to avoid CI flakiness)
            $this->assertLessThan(250.0, $durationMs, "Strategy '$name' took {$durationMs}ms for 1000 iterations");
        }
    }
}
