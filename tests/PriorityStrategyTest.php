<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests;

use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Routing\PriorityStrategy;
use CleatSquad\LlmRouter\Tests\Fixtures\FakeDriver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PriorityStrategyTest extends TestCase
{
    public function testSelectsHighestPriorityAvailableDriver(): void
    {
        $strategy = new PriorityStrategy(priorities: ['a' => 10, 'b' => 20, 'c' => 5]);
        $drivers = [new FakeDriver('a'), new FakeDriver('b'), new FakeDriver('c')];

        $selected = $strategy->select(new LLMRequest(messages: []), $drivers);

        $this->assertSame('b', $selected->getId());
    }

    public function testSkipsUnavailableDriversInPriorityOrder(): void
    {
        $strategy = new PriorityStrategy(priorities: ['a' => 10, 'b' => 20]);
        $drivers = [new FakeDriver('a', available: true), new FakeDriver('b', available: false)];

        $selected = $strategy->select(new LLMRequest(messages: []), $drivers);

        $this->assertSame('a', $selected->getId());
    }

    public function testUsesQualityPrioritiesWhenPreferQualityIsSet(): void
    {
        $strategy = new PriorityStrategy(
            priorities: ['fast' => 20, 'quality' => 5],
            qualityPriorities: ['fast' => 5, 'quality' => 20]
        );
        $drivers = [new FakeDriver('fast'), new FakeDriver('quality')];

        $selected = $strategy->select(new LLMRequest(messages: [], preferQuality: true), $drivers);

        $this->assertSame('quality', $selected->getId());
    }

    public function testThrowsWhenNoDriversProvided(): void
    {
        $strategy = new PriorityStrategy();

        $this->expectException(RuntimeException::class);
        $strategy->select(new LLMRequest(messages: []), []);
    }

    public function testThrowsWhenAllDriversUnavailable(): void
    {
        $strategy = new PriorityStrategy(priorities: ['a' => 10]);

        $this->expectException(RuntimeException::class);
        $strategy->select(new LLMRequest(messages: []), [new FakeDriver('a', available: false)]);
    }
}
