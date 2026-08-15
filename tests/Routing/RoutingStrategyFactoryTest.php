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
    }

    public function testThrowsForUnknownStrategy(): void
    {
        $factory = new RoutingStrategyFactory();
        $this->expectException(InvalidArgumentException::class);
        $factory->create('invalid-strategy');
    }
}
