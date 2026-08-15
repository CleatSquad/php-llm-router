<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Routing;

use CleatSquad\LlmRouter\Routing\CostStrategy;
use CleatSquad\LlmRouter\Routing\LatencyStrategy;
use CleatSquad\LlmRouter\Routing\LeastBusyStrategy;
use CleatSquad\LlmRouter\Routing\PriorityStrategy;
use CleatSquad\LlmRouter\Routing\RandomStrategy;
use CleatSquad\LlmRouter\Routing\RoundRobinStrategy;
use CleatSquad\LlmRouter\Routing\RoutingStrategyFactory;
use CleatSquad\LlmRouter\Routing\WeightedStrategy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RoutingStrategyFactoryTest extends TestCase
{
    public function testCreatesAllKnownStrategies(): void
    {
        $factory = new RoutingStrategyFactory();

        $this->assertInstanceOf(\CleatSquad\LlmRouter\Contract\RoutingStrategyInterface::class, $factory->create('priority'));
        $this->assertInstanceOf(\CleatSquad\LlmRouter\Contract\RoutingStrategyInterface::class, $factory->create('weighted'));
        $this->assertInstanceOf(\CleatSquad\LlmRouter\Contract\RoutingStrategyInterface::class, $factory->create('random'));
        $this->assertInstanceOf(\CleatSquad\LlmRouter\Contract\RoutingStrategyInterface::class, $factory->create('least-busy'));
        $this->assertInstanceOf(\CleatSquad\LlmRouter\Contract\RoutingStrategyInterface::class, $factory->create('latency'));
        $this->assertInstanceOf(\CleatSquad\LlmRouter\Contract\RoutingStrategyInterface::class, $factory->create('cost'));
        $this->assertInstanceOf(\CleatSquad\LlmRouter\Contract\RoutingStrategyInterface::class, $factory->create('round-robin'));
        $this->assertInstanceOf(\CleatSquad\LlmRouter\Contract\RoutingStrategyInterface::class, $factory->create('usage'));
        $this->assertInstanceOf(\CleatSquad\LlmRouter\Contract\RoutingStrategyInterface::class, $factory->create('reliability'));
        $this->assertInstanceOf(\CleatSquad\LlmRouter\Contract\RoutingStrategyInterface::class, $factory->create('quota'));
    }

    public function testFactoryLeastBusyStrategyUsesTracker(): void
    {
        $tracker = new \CleatSquad\LlmRouter\Routing\InMemoryActiveRequestsTracker();
        $tracker->increment('d1');
        $tracker->increment('d1');

        $factory = new RoutingStrategyFactory(activeRequestsTracker: $tracker);
        $strategy = $factory->create('least-busy');

        $d1 = new \CleatSquad\LlmRouter\Tests\Fixtures\FakeDriver('d1');
        $d2 = new \CleatSquad\LlmRouter\Tests\Fixtures\FakeDriver('d2');
        $request = new \CleatSquad\LlmRouter\DTO\LLMRequest(messages: []);

        $selected = $strategy->select($request, [$d1, $d2]);
        $this->assertSame('d2', $selected->getId());
    }

    public function testFactoryUsageStrategyUsesTracker(): void
    {
        $tracker = new \CleatSquad\LlmRouter\Routing\InMemoryUsageTracker();
        $tracker->recordUsage('d1', 100);

        $factory = new RoutingStrategyFactory(usageTracker: $tracker);
        $strategy = $factory->create('usage');

        $d1 = new \CleatSquad\LlmRouter\Tests\Fixtures\FakeDriver('d1');
        $d2 = new \CleatSquad\LlmRouter\Tests\Fixtures\FakeDriver('d2');
        $request = new \CleatSquad\LlmRouter\DTO\LLMRequest(messages: []);

        $selected = $strategy->select($request, [$d1, $d2]);
        $this->assertSame('d2', $selected->getId());
    }

    public function testFactoryReliabilityStrategyUsesTracker(): void
    {
        $tracker = new \CleatSquad\LlmRouter\Routing\InMemoryReliabilityTracker(minSamples: 1);
        $tracker->recordSuccess('d1');
        $tracker->recordFailure('d2');

        $factory = new RoutingStrategyFactory(reliabilityTracker: $tracker);
        $strategy = $factory->create('reliability');

        $d1 = new \CleatSquad\LlmRouter\Tests\Fixtures\FakeDriver('d1');
        $d2 = new \CleatSquad\LlmRouter\Tests\Fixtures\FakeDriver('d2');
        $request = new \CleatSquad\LlmRouter\DTO\LLMRequest(messages: []);

        $selected = $strategy->select($request, [$d1, $d2]);
        $this->assertSame('d1', $selected->getId());
    }

    public function testThrowsForUnknownStrategy(): void
    {
        $factory = new RoutingStrategyFactory();
        $this->expectException(InvalidArgumentException::class);
        $factory->create('invalid-strategy');
    }
}
