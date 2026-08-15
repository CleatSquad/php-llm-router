<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Unit\Adapter;

use CleatSquad\LlmRouter\Adapter\RoutingPolicyAdapter;
use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Policy\RoutingPolicy;
use PHPUnit\Framework\TestCase;

final class AdaptersTest extends TestCase
{
    public function testRoutingPolicyAdapterImplementsRoutingStrategyInterface(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $driver->method('getId')->willReturn('claude');
        $driver->method('getName')->willReturn('Claude');
        $driver->method('isAvailable')->willReturn(true);

        $policy = new RoutingPolicy(constraints: [], rankers: [], name: 'adapter-test');
        $adapter = new RoutingPolicyAdapter($policy);

        $selected = $adapter->select(new LLMRequest(messages: []), [$driver]);
        $this->assertSame($driver, $selected);
        $this->assertNotNull($adapter->getLastDecision());
    }

    public function testLegacyStrategyAdapterToPolicy(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $driver->method('getId')->willReturn('d1');
        $driver->method('isAvailable')->willReturn(true);

        $legacyStrategy = $this->createMock(\CleatSquad\LlmRouter\Contract\RoutingStrategyInterface::class);
        $legacyStrategy->method('select')->willReturn($driver);

        $policy = \CleatSquad\LlmRouter\Adapter\LegacyStrategyAdapter::toPolicy($legacyStrategy, 'legacy-test');
        $this->assertSame('legacy-test', $policy->name);

        $engine = new \CleatSquad\LlmRouter\Engine\RoutingEngine($policy);
        $decision = $engine->decide(new LLMRequest(messages: []), [$driver]);

        $this->assertSame('d1', $decision->selected->id);
    }
}
