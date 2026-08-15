<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Routing;

use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Routing\ContextWindowStrategy;
use CleatSquad\LlmRouter\Routing\InMemoryUsageTracker;
use CleatSquad\LlmRouter\Routing\PriorityStrategy;
use CleatSquad\LlmRouter\Routing\UsageStrategy;
use CleatSquad\LlmRouter\Tests\Fixtures\FakeDriver;
use PHPUnit\Framework\TestCase;

final class AdditionalStrategiesTest extends TestCase
{
    public function testUsageStrategySelectsLeastUsed(): void
    {
        $d1 = new FakeDriver('provider-a');
        $d2 = new FakeDriver('provider-b');

        $tracker = new InMemoryUsageTracker();
        $tracker->recordUsage('provider-a', 5000);
        $tracker->recordUsage('provider-b', 1200);

        $strategy = new UsageStrategy($tracker);
        $request = new LLMRequest(messages: [['role' => 'user', 'content' => 'Hello']]);

        $selected = $strategy->select($request, [$d1, $d2]);
        $this->assertSame('provider-b', $selected->getId());
    }

    public function testContextWindowStrategyFiltersByCapacity(): void
    {
        $d1 = new FakeDriver('small-context-provider'); // max 10 tokens
        $d2 = new FakeDriver('large-context-provider'); // max 1000 tokens

        $strategy = new ContextWindowStrategy(
            maxContextTokens: [
                'small-context-provider' => 10,
                'large-context-provider' => 1000,
            ],
            fallbackStrategy: new PriorityStrategy(['small-context-provider' => 10, 'large-context-provider' => 5])
        );

        // Request with ~100 characters ≈ 25 tokens -> small-context-provider cannot handle it
        $request = new LLMRequest(messages: [
            ['role' => 'user', 'content' => str_repeat('Long prompt text here ', 5)],
        ]);

        $selected = $strategy->select($request, [$d1, $d2]);
        $this->assertSame('large-context-provider', $selected->getId());
    }
}
