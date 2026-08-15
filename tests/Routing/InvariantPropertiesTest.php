<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Routing;

use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Routing\CapabilityStrategy;
use CleatSquad\LlmRouter\Routing\ContextWindowStrategy;
use CleatSquad\LlmRouter\Routing\CostStrategy;
use CleatSquad\LlmRouter\Routing\InMemoryActiveRequestsTracker;
use CleatSquad\LlmRouter\Routing\InMemoryQuotaTracker;
use CleatSquad\LlmRouter\Routing\InMemoryReliabilityTracker;
use CleatSquad\LlmRouter\Routing\LeastBusyStrategy;
use CleatSquad\LlmRouter\Routing\PriorityStrategy;
use CleatSquad\LlmRouter\Routing\QuotaStrategy;
use CleatSquad\LlmRouter\Routing\RandomStrategy;
use CleatSquad\LlmRouter\Routing\ReliabilityStrategy;
use CleatSquad\LlmRouter\Routing\WeightedStrategy;
use CleatSquad\LlmRouter\Tests\Fixtures\FakeDriver;
use CleatSquad\LlmRouter\Tests\Fixtures\FakeRandomizer;
use PHPUnit\Framework\TestCase;

final class InvariantPropertiesTest extends TestCase
{
    private LLMRequest $request;

    protected function setUp(): void
    {
        $this->request = new LLMRequest(messages: [['role' => 'user', 'content' => 'Invariant test prompt']]);
    }

    public function testUnavailableDriversAreNeverSelectedByAnyStrategy(): void
    {
        $unavailable1 = new FakeDriver('unavail-1', available: false);
        $unavailable2 = new FakeDriver('unavail-2', available: false);
        $available = new FakeDriver('avail-1', available: true);

        $drivers = [$unavailable1, $unavailable2, $available];

        $strategies = [
            new PriorityStrategy(['unavail-1' => 100, 'avail-1' => 1]),
            new RandomStrategy(),
            new WeightedStrategy(['unavail-1' => 100, 'avail-1' => 1]),
            new LeastBusyStrategy(new InMemoryActiveRequestsTracker()),
            new CostStrategy(),
            new CapabilityStrategy(),
            new ReliabilityStrategy(new InMemoryReliabilityTracker()),
            new QuotaStrategy(new InMemoryQuotaTracker()),
        ];

        foreach ($strategies as $strategy) {
            $selected = $strategy->select($this->request, $drivers);
            $this->assertSame('avail-1', $selected->getId(), get_class($strategy) . ' selected an unavailable driver!');
        }
    }

    public function testZeroWeightDriverIsNeverSelectedInWeightedStrategy(): void
    {
        $zeroWeightDriver = new FakeDriver('zero-weight');
        $normalDriver = new FakeDriver('normal-weight');

        $randomizer = new FakeRandomizer([1, 2, 3]);
        $strategy = new WeightedStrategy([
            'zero-weight' => 0,
            'normal-weight' => 10,
        ], $randomizer);

        $selected = $strategy->select($this->request, [$zeroWeightDriver, $normalDriver]);
        $this->assertSame('normal-weight', $selected->getId());
    }

    public function testCapabilityStrategyNeverSelectsIncompatibleDriver(): void
    {
        $noToolsDriver = new FakeDriver('no-tools', supportsTools: false);
        $toolsDriver = new FakeDriver('has-tools', supportsTools: true);

        $strategy = new CapabilityStrategy(requireTools: true);

        $selected = $strategy->select($this->request, [$noToolsDriver, $toolsDriver]);
        $this->assertSame('has-tools', $selected->getId());
    }

    public function testContextWindowStrategyNeverSelectsOverCapacityDriver(): void
    {
        $smallContext = new FakeDriver('small-ctx'); // max 10 tokens
        $largeContext = new FakeDriver('large-ctx'); // max 1000 tokens

        $strategy = new ContextWindowStrategy(
            maxContextTokens: ['small-ctx' => 10, 'large-ctx' => 1000]
        );

        // Prompt ~ 25 tokens
        $longRequest = new LLMRequest(messages: [
            ['role' => 'user', 'content' => str_repeat('Testing context capacity ', 5)],
        ]);

        $selected = $strategy->select($longRequest, [$smallContext, $largeContext]);
        $this->assertSame('large-ctx', $selected->getId());
    }
}
