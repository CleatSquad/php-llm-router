<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Driver;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\Driver\FailoverDriver;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Exception\AllDriversFailedException;
use CleatSquad\LlmRouter\Routing\PriorityStrategy;
use CleatSquad\LlmRouter\Tests\Fixtures\ControllableDriver;
use CleatSquad\LlmRouter\Tests\Fixtures\PartialStreamDriver;
use CleatSquad\LlmRouter\Tests\Fixtures\RecordingLogger;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TypeError;

final class FailoverDriverTest extends TestCase
{
    private function request(): LLMRequest
    {
        return new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]);
    }

    /**
     * Highest priority first, matching array order — deterministic
     * fail-over order for these tests.
     *
     * @param LLMDriverInterface[] $drivers
     */
    private function strategyFor(array $drivers): PriorityStrategy
    {
        $priorities = [];
        $weight = count($drivers);
        foreach ($drivers as $driver) {
            $priorities[$driver->getId()] = $weight--;
        }

        return new PriorityStrategy(priorities: $priorities);
    }

    public function testRequiresAtLeastOneDriver(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FailoverDriver($this->strategyFor([]), []);
    }

    public function testSucceedsOnFirstDriverWithoutFailover(): void
    {
        $a = new ControllableDriver('a');
        $b = new ControllableDriver('b');
        $router = new FailoverDriver($this->strategyFor([$a, $b]), [$a, $b]);

        $response = $router->chat($this->request());

        $this->assertSame('ok', $response->content);
        $this->assertSame(1, $a->callCount);
        $this->assertSame(0, $b->callCount);
    }

    public function testFailsOverToNextDriverOnRuntimeException(): void
    {
        $a = new ControllableDriver('a', [new RuntimeException('boom')]);
        $b = new ControllableDriver('b');
        $router = new FailoverDriver($this->strategyFor([$a, $b]), [$a, $b]);

        $response = $router->chat($this->request());

        $this->assertSame('ok', $response->content);
        $this->assertSame(1, $a->callCount);
        $this->assertSame(1, $b->callCount);
    }

    public function testThrowsStructuredExceptionWhenAllDriversFail(): void
    {
        $a = new ControllableDriver('a', [new RuntimeException('a is down')]);
        $b = new ControllableDriver('b', [new RuntimeException('b is down')]);
        $router = new FailoverDriver($this->strategyFor([$a, $b]), [$a, $b]);

        try {
            $router->chat($this->request());
            $this->fail('Expected AllDriversFailedException.');
        } catch (AllDriversFailedException $e) {
            $failures = $e->getFailures();
            $this->assertCount(2, $failures);
            $this->assertSame('a', $failures[0]['driverId']);
            $this->assertSame('a is down', $failures[0]['exception']->getMessage());
            $this->assertSame('b', $failures[1]['driverId']);
            $this->assertSame('b is down', $failures[1]['exception']->getMessage());
        }
    }

    public function testNonRuntimeExceptionPropagatesImmediatelyWithoutFailover(): void
    {
        $a = new ControllableDriver('a', [new TypeError('bug')]);
        $b = new ControllableDriver('b');
        $router = new FailoverDriver($this->strategyFor([$a, $b]), [$a, $b]);

        try {
            $router->chat($this->request());
            $this->fail('Expected TypeError.');
        } catch (TypeError) {
            $this->assertSame(1, $a->callCount);
            $this->assertSame(0, $b->callCount);
        }
    }

    public function testShouldFailoverCallbackCanSuppressFailover(): void
    {
        $a = new ControllableDriver('a', [new RuntimeException('boom')]);
        $b = new ControllableDriver('b');
        $router = new FailoverDriver(
            $this->strategyFor([$a, $b]),
            [$a, $b],
            shouldFailover: static fn (RuntimeException $e, LLMDriverInterface $d): bool => false,
        );

        try {
            $router->chat($this->request());
            $this->fail('Expected RuntimeException to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
            $this->assertSame(1, $a->callCount);
            $this->assertSame(0, $b->callCount);
        }
    }

    public function testStreamFailsOverBeforeFirstChunk(): void
    {
        $a = new PartialStreamDriver('a', [], failure: new RuntimeException('boom'));
        $b = new PartialStreamDriver('b', ['hel', 'lo']);
        $router = new FailoverDriver($this->strategyFor([$a, $b]), [$a, $b]);

        $chunks = iterator_to_array($router->stream($this->request()));

        $this->assertSame(['hel', 'lo'], $chunks);
    }

    public function testStreamDoesNotFailoverAfterFirstChunk(): void
    {
        $a = new PartialStreamDriver('a', ['par', 'tial'], failure: new RuntimeException('boom'));
        $b = new PartialStreamDriver('b', ['should', 'not', 'be', 'used']);
        $router = new FailoverDriver($this->strategyFor([$a, $b]), [$a, $b]);

        $seen = [];
        try {
            foreach ($router->stream($this->request()) as $chunk) {
                $seen[] = $chunk;
            }
            $this->fail('Expected RuntimeException to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
            $this->assertSame(['par', 'tial'], $seen);
        }
    }

    public function testHealthCheckReturnsFirstHealthyDriver(): void
    {
        $a = new ControllableDriver('a'); // healthy by default
        $b = new ControllableDriver('b');
        $router = new FailoverDriver($this->strategyFor([$a, $b]), [$a, $b]);

        $status = $router->healthCheck();

        $this->assertTrue($status->isHealthy());
    }

    public function testEstimateCostDelegatesToStrategySelectedDriver(): void
    {
        $a = new ControllableDriver('a');
        $b = new ControllableDriver('b');
        // b has higher priority here, so estimateCost() should reflect b.
        $router = new FailoverDriver(new PriorityStrategy(priorities: ['b' => 10, 'a' => 1]), [$a, $b]);

        $estimate = $router->estimateCost($this->request());

        $this->assertSame(0.0, $estimate->estimatedCostUsd); // both fakes are free; asserts no exception/mismatch
    }

    public function testLogsFailoverAndAllDriversFailed(): void
    {
        $a = new ControllableDriver('a', [new RuntimeException('a is down')]);
        $b = new ControllableDriver('b', [new RuntimeException('b is down')]);
        $logger = new RecordingLogger();
        $router = new FailoverDriver($this->strategyFor([$a, $b]), [$a, $b], logger: $logger);

        try {
            $router->chat($this->request());
        } catch (AllDriversFailedException) {
            // expected
        }

        $this->assertCount(3, $logger->records);
        $this->assertSame('llm_router.failover', $logger->records[0]['message']);
        $this->assertSame('a', $logger->records[0]['context']['driver_id']);
        $this->assertSame('llm_router.failover', $logger->records[1]['message']);
        $this->assertSame('b', $logger->records[1]['context']['driver_id']);
        $this->assertSame('llm_router.all_drivers_failed', $logger->records[2]['message']);
    }
}
