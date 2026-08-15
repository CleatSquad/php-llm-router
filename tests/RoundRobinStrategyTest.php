<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests;

use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Routing\RoundRobinStrategy;
use CleatSquad\LlmRouter\Tests\Fixtures\FakeDriver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RoundRobinStrategyTest extends TestCase
{
    public function testCyclesThroughDriversInOrder(): void
    {
        $strategy = new RoundRobinStrategy();
        $drivers = [new FakeDriver('a'), new FakeDriver('b'), new FakeDriver('c')];
        $request = new LLMRequest(messages: []);

        $picks = [
            $strategy->select($request, $drivers)->getId(),
            $strategy->select($request, $drivers)->getId(),
            $strategy->select($request, $drivers)->getId(),
            $strategy->select($request, $drivers)->getId(),
        ];

        $this->assertSame(['a', 'b', 'c', 'a'], $picks);
    }

    public function testSkipsUnavailableDriversWithoutConsumingTheirTurn(): void
    {
        $strategy = new RoundRobinStrategy();
        $drivers = [new FakeDriver('a'), new FakeDriver('b', available: false), new FakeDriver('c')];
        $request = new LLMRequest(messages: []);

        $picks = [
            $strategy->select($request, $drivers)->getId(),
            $strategy->select($request, $drivers)->getId(),
            $strategy->select($request, $drivers)->getId(),
        ];

        $this->assertSame(['a', 'c', 'a'], $picks);
    }

    public function testWeightsBiasHowOftenADriverIsOffered(): void
    {
        $strategy = new RoundRobinStrategy(weights: ['a' => 2, 'b' => 1]);
        $drivers = [new FakeDriver('a'), new FakeDriver('b')];
        $request = new LLMRequest(messages: []);

        $picks = [
            $strategy->select($request, $drivers)->getId(),
            $strategy->select($request, $drivers)->getId(),
            $strategy->select($request, $drivers)->getId(),
        ];

        $this->assertSame(['a', 'a', 'b'], $picks);
    }

    public function testThrowsWhenNoDriversProvided(): void
    {
        $strategy = new RoundRobinStrategy();

        $this->expectException(RuntimeException::class);
        $strategy->select(new LLMRequest(messages: []), []);
    }

    public function testThrowsWhenAllDriversUnavailable(): void
    {
        $strategy = new RoundRobinStrategy();

        $this->expectException(RuntimeException::class);
        $strategy->select(new LLMRequest(messages: []), [new FakeDriver('a', available: false)]);
    }
}
