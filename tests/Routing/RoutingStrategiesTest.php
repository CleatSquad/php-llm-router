<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Routing;

use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Routing\NativeRandomizer;
use CleatSquad\LlmRouter\Routing\RandomStrategy;
use CleatSquad\LlmRouter\Routing\WeightedStrategy;
use CleatSquad\LlmRouter\Tests\Fixtures\FakeDriver;
use CleatSquad\LlmRouter\Tests\Fixtures\FakeRandomizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RoutingStrategiesTest extends TestCase
{
    private LLMRequest $request;

    protected function setUp(): void
    {
        $this->request = new LLMRequest(messages: [['role' => 'user', 'content' => 'Hello']]);
    }

    public function testWeightedStrategySelectionDeterministic(): void
    {
        $d1 = new FakeDriver('openai');
        $d2 = new FakeDriver('anthropic');
        $d3 = new FakeDriver('gemini');

        // Weights: openai=70, anthropic=20, gemini=10 -> Total=100
        $randomizer = new FakeRandomizer([50, 75, 95]); // 50 -> openai, 75 -> anthropic (70+20=90), 95 -> gemini (90+10=100)
        $strategy = new WeightedStrategy([
            'openai' => 70,
            'anthropic' => 20,
            'gemini' => 10,
        ], $randomizer);

        $selected1 = $strategy->select($this->request, [$d1, $d2, $d3]);
        $this->assertSame('openai', $selected1->getId());

        $selected2 = $strategy->select($this->request, [$d1, $d2, $d3]);
        $this->assertSame('anthropic', $selected2->getId());

        $selected3 = $strategy->select($this->request, [$d1, $d2, $d3]);
        $this->assertSame('gemini', $selected3->getId());
    }

    public function testWeightedStrategySkipsUnavailableDrivers(): void
    {
        $d1 = new FakeDriver('openai', available: false);
        $d2 = new FakeDriver('anthropic', available: true);

        $strategy = new WeightedStrategy(['openai' => 100, 'anthropic' => 10]);
        $selected = $strategy->select($this->request, [$d1, $d2]);

        $this->assertSame('anthropic', $selected->getId());
    }

    public function testWeightedStrategyThrowsWhenAllUnavailable(): void
    {
        $d1 = new FakeDriver('openai', available: false);

        $strategy = new WeightedStrategy();
        $this->expectException(RuntimeException::class);
        $strategy->select($this->request, [$d1]);
    }

    public function testWeightedStrategyInvalidWeightThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new WeightedStrategy(['openai' => -10]);
    }

    public function testRandomStrategyDeterministicWithFakeRandomizer(): void
    {
        $d1 = new FakeDriver('openai');
        $d2 = new FakeDriver('anthropic');

        $randomizer = new FakeRandomizer([1]); // Picks index 1 -> anthropic
        $strategy = new RandomStrategy($randomizer);

        $selected = $strategy->select($this->request, [$d1, $d2]);
        $this->assertSame('anthropic', $selected->getId());
    }

    public function testRandomStrategyNeverSelectsUnavailable(): void
    {
        $d1 = new FakeDriver('openai', available: false);
        $d2 = new FakeDriver('anthropic', available: true);

        $strategy = new RandomStrategy();
        $selected = $strategy->select($this->request, [$d1, $d2]);
        $this->assertSame('anthropic', $selected->getId());
    }

    public function testNativeRandomizer(): void
    {
        $randomizer = new NativeRandomizer();
        $int = $randomizer->nextInt(1, 10);
        $float = $randomizer->nextFloat();

        $this->assertGreaterThanOrEqual(1, $int);
        $this->assertLessThanOrEqual(10, $int);
        $this->assertGreaterThanOrEqual(0.0, $float);
        $this->assertLessThan(1.0, $float);
    }
}
