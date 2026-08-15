<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Routing;

use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Routing\CapabilityStrategy;
use CleatSquad\LlmRouter\Routing\CompositeStrategy;
use CleatSquad\LlmRouter\Routing\ContextWindowStrategy;
use CleatSquad\LlmRouter\Routing\CostStrategy;
use CleatSquad\LlmRouter\Routing\InMemoryQuotaTracker;
use CleatSquad\LlmRouter\Routing\InMemoryReliabilityTracker;
use CleatSquad\LlmRouter\Routing\PriorityStrategy;
use CleatSquad\LlmRouter\Routing\QuotaStrategy;
use CleatSquad\LlmRouter\Routing\ReliabilityStrategy;
use CleatSquad\LlmRouter\Tests\Fixtures\FakeDriver;
use PHPUnit\Framework\TestCase;

final class AdvancedRoutingStrategiesTest extends TestCase
{
    private LLMRequest $request;

    protected function setUp(): void
    {
        $this->request = new LLMRequest(messages: [['role' => 'user', 'content' => 'Test prompt']]);
    }

    public function testCapabilityStrategyFiltersIncompatibleDrivers(): void
    {
        $d1 = new FakeDriver('no-tools-provider', supportsTools: false);
        $d2 = new FakeDriver('tools-provider', supportsTools: true);

        $strategy = new CapabilityStrategy(requireTools: true);

        $selected = $strategy->select($this->request, [$d1, $d2]);
        $this->assertSame('tools-provider', $selected->getId());
    }

    public function testReliabilityStrategySelectsHighestSuccessRate(): void
    {
        $d1 = new FakeDriver('unreliable-provider');
        $d2 = new FakeDriver('reliable-provider');

        $tracker = new InMemoryReliabilityTracker(minSamples: 2);
        // Unreliable: 2 success / 8 failure = 20%
        for ($i = 0; $i < 2; $i++) { $tracker->recordSuccess('unreliable-provider'); }
        for ($i = 0; $i < 8; $i++) { $tracker->recordFailure('unreliable-provider'); }

        // Reliable: 9 success / 1 failure = 90%
        for ($i = 0; $i < 9; $i++) { $tracker->recordSuccess('reliable-provider'); }
        $tracker->recordFailure('reliable-provider');

        $strategy = new ReliabilityStrategy($tracker);
        $selected = $strategy->select($this->request, [$d1, $d2]);

        $this->assertSame('reliable-provider', $selected->getId());
    }

    public function testQuotaStrategyExcludesExhaustedQuota(): void
    {
        $d1 = new FakeDriver('exhausted-provider');
        $d2 = new FakeDriver('abundant-provider');

        $tracker = new InMemoryQuotaTracker([
            'exhausted-provider' => 0.0,
            'abundant-provider' => 0.75,
        ]);

        $strategy = new QuotaStrategy($tracker);
        $selected = $strategy->select($this->request, [$d1, $d2]);

        $this->assertSame('abundant-provider', $selected->getId());
    }

    public function testCompositeStrategyChainsConstraintsAndRanking(): void
    {
        $d1 = new FakeDriver('cheap-no-tools', supportsTools: false);
        $d2 = new FakeDriver('expensive-tools', supportsTools: true);
        $d3 = new FakeDriver('cheap-tools', supportsTools: true);

        // CapabilityStrategy as hard constraint with PriorityStrategy as fallback ranking strategy
        $strategy = new CapabilityStrategy(
            requireTools: true,
            fallbackStrategy: new PriorityStrategy(['cheap-tools' => 10, 'expensive-tools' => 5])
        );

        $selected = $strategy->select($this->request, [$d1, $d2, $d3]);
        $this->assertSame('cheap-tools', $selected->getId());
    }
}
