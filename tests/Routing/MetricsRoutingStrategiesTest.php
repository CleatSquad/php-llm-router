<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Routing;

use CleatSquad\LlmRouter\DTO\CostEstimate;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Routing\CostStrategy;
use CleatSquad\LlmRouter\Routing\InMemoryActiveRequestsTracker;
use CleatSquad\LlmRouter\Routing\InMemoryLatencyTracker;
use CleatSquad\LlmRouter\Routing\LatencyStrategy;
use CleatSquad\LlmRouter\Routing\LeastBusyStrategy;
use CleatSquad\LlmRouter\Tests\Fixtures\FakeDriver;
use PHPUnit\Framework\TestCase;

final class MetricsRoutingStrategiesTest extends TestCase
{
    private LLMRequest $request;

    protected function setUp(): void
    {
        $this->request = new LLMRequest(messages: [['role' => 'user', 'content' => 'Test message for cost calculation']]);
    }

    public function testLeastBusyStrategySelectsLowestActiveRequests(): void
    {
        $d1 = new FakeDriver('provider-a');
        $d2 = new FakeDriver('provider-b');
        $d3 = new FakeDriver('provider-c');

        $tracker = new InMemoryActiveRequestsTracker();
        $tracker->increment('provider-a'); // 1
        $tracker->increment('provider-a'); // 2
        $tracker->increment('provider-c'); // 1
        // provider-b remains 0

        $strategy = new LeastBusyStrategy($tracker);
        $selected = $strategy->select($this->request, [$d1, $d2, $d3]);

        $this->assertSame('provider-b', $selected->getId());
    }

    public function testLeastBusyStrategyTieBreaker(): void
    {
        $d1 = new FakeDriver('provider-a');
        $d2 = new FakeDriver('provider-b');

        $tracker = new InMemoryActiveRequestsTracker(); // both 0
        $strategy = new LeastBusyStrategy($tracker);

        $selected = $strategy->select($this->request, [$d1, $d2]);
        $this->assertSame('provider-a', $selected->getId()); // Maintains candidates array order
    }

    public function testLatencyStrategySelectsLowestLatency(): void
    {
        $d1 = new FakeDriver('fast-provider');
        $d2 = new FakeDriver('slow-provider');

        $tracker = new InMemoryLatencyTracker();
        $tracker->recordLatencyMs('fast-provider', 120.0);
        $tracker->recordLatencyMs('slow-provider', 450.0);

        $strategy = new LatencyStrategy($tracker);
        $selected = $strategy->select($this->request, [$d1, $d2]);

        $this->assertSame('fast-provider', $selected->getId());
    }

    public function testLatencyStrategyWarmUpFallback(): void
    {
        $d1 = new FakeDriver('known-provider');
        $d2 = new FakeDriver('new-provider'); // no history

        $tracker = new InMemoryLatencyTracker();
        $tracker->recordLatencyMs('known-provider', 300.0);

        // Warm up default is 0.0ms, so new-provider will be selected
        $strategy = new LatencyStrategy($tracker, defaultLatencyMs: 0.0);
        $selected = $strategy->select($this->request, [$d1, $d2]);

        $this->assertSame('new-provider', $selected->getId());
    }

    public function testCostStrategySelectsLowestEstimatedCost(): void
    {
        $d1 = new FakeDriver('cheap-provider', costEstimate: new CostEstimate(0.001, 0.002, 10, 0.0001));
        $d2 = new FakeDriver('expensive-provider', costEstimate: new CostEstimate(0.01, 0.03, 10, 0.005));

        $strategy = new CostStrategy();
        $selected = $strategy->select($this->request, [$d1, $d2]);

        $this->assertSame('cheap-provider', $selected->getId());
    }
}
