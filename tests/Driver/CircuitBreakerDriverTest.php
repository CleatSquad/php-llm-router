<?php

declare(strict_types=1);

namespace LlmRouter\Tests\Driver;

use LlmRouter\CircuitBreaker\InMemoryCircuitBreakerStore;
use LlmRouter\Driver\CircuitBreakerDriver;
use LlmRouter\DTO\LLMRequest;
use LlmRouter\Tests\Fixtures\ControllableDriver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CircuitBreakerDriverTest extends TestCase
{
    private function request(): LLMRequest
    {
        return new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]);
    }

    public function testStaysClosedAndDelegatesOnSuccess(): void
    {
        $inner = new ControllableDriver('fake');
        $breaker = new CircuitBreakerDriver($inner, failureThreshold: 3, openSeconds: 60);

        $response = $breaker->chat($this->request());

        $this->assertSame('ok', $response->content);
        $this->assertTrue($breaker->isAvailable());
    }

    public function testOpensAfterConsecutiveFailuresAndFailsFast(): void
    {
        $failure = new RuntimeException('boom');
        $inner = new ControllableDriver('fake', [$failure, $failure, $failure]);
        $breaker = new CircuitBreakerDriver($inner, failureThreshold: 3, openSeconds: 60);

        for ($i = 0; $i < 3; $i++) {
            try {
                $breaker->chat($this->request());
                $this->fail('expected the scripted failure to propagate');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
        }

        $this->assertFalse($breaker->isAvailable());

        $callsBefore = $inner->callCount;
        try {
            $breaker->chat($this->request());
            $this->fail('expected a circuit-open exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Circuit breaker open', $e->getMessage());
        }
        $this->assertSame($callsBefore, $inner->callCount, 'the inner driver must not be called while the circuit is open');
    }

    public function testSuccessResetsTheFailureCounter(): void
    {
        $failure = new RuntimeException('boom');
        $inner = new ControllableDriver('fake', [$failure, $failure, null, $failure, $failure]);
        $breaker = new CircuitBreakerDriver($inner, failureThreshold: 3, openSeconds: 60);

        for ($i = 0; $i < 2; $i++) {
            try {
                $breaker->chat($this->request());
            } catch (RuntimeException) {
            }
        }
        $breaker->chat($this->request()); // success resets the counter

        for ($i = 0; $i < 2; $i++) {
            try {
                $breaker->chat($this->request());
            } catch (RuntimeException) {
            }
        }

        $this->assertTrue($breaker->isAvailable(), '2 failures after a reset must not be enough to trip a threshold of 3');
    }

    public function testClosesAgainAfterTheOpenWindowElapses(): void
    {
        $failure = new RuntimeException('boom');
        $inner = new ControllableDriver('fake', [$failure]);
        $store = new InMemoryCircuitBreakerStore();
        $breaker = new CircuitBreakerDriver($inner, $store, failureThreshold: 1, openSeconds: -1);

        try {
            $breaker->chat($this->request());
        } catch (RuntimeException) {
        }

        $this->assertTrue($breaker->isAvailable(), 'a negative openSeconds means openUntil is already in the past');
    }

    public function testStreamFailurePropagatesAndTripsTheBreakerToo(): void
    {
        $failure = new RuntimeException('stream boom');
        $inner = new ControllableDriver('fake', [$failure]);
        $breaker = new CircuitBreakerDriver($inner, failureThreshold: 1, openSeconds: 60);

        try {
            iterator_to_array($breaker->stream($this->request()));
            $this->fail('expected the scripted failure to propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('stream boom', $e->getMessage());
        }

        $this->assertFalse($breaker->isAvailable());
    }

    public function testStreamYieldsAndReturnValuePassThroughOnSuccess(): void
    {
        $inner = new ControllableDriver('fake');
        $breaker = new CircuitBreakerDriver($inner, failureThreshold: 3, openSeconds: 60);

        $gen = $breaker->stream($this->request());
        $chunks = iterator_to_array($gen);

        $this->assertSame(['ok'], $chunks);
        $this->assertNull($gen->getReturn());
    }

    public function testHealthCheckReportsUnhealthyWhileOpenWithoutCallingInner(): void
    {
        $failure = new RuntimeException('boom');
        $inner = new ControllableDriver('fake', [$failure]);
        $breaker = new CircuitBreakerDriver($inner, failureThreshold: 1, openSeconds: 60);

        try {
            $breaker->chat($this->request());
        } catch (RuntimeException) {
        }

        $status = $breaker->healthCheck();

        $this->assertFalse($status->isHealthy());
        $this->assertStringContainsString('Circuit breaker open', (string) $status->message);
    }

    public function testRateLimitExceptionSetsCustomOpenDuration(): void
    {
        $rateLimitFailure = new \LlmRouter\Exception\RateLimitException('Rate limit hit', 3442, 429);
        $inner = new ControllableDriver('fake', [$rateLimitFailure]);
        $store = new InMemoryCircuitBreakerStore();
        $breaker = new CircuitBreakerDriver($inner, $store, failureThreshold: 1, openSeconds: 60);

        try {
            $breaker->chat($this->request());
        } catch (\LlmRouter\Exception\RateLimitException) {
        }

        $state = $store->getState('fake');
        $this->assertNotNull($state->openUntil);
        $diff = $state->openUntil->getTimestamp() - (new \DateTimeImmutable())->getTimestamp();
        $this->assertGreaterThanOrEqual(3440, $diff);
        $this->assertLessThanOrEqual(3445, $diff);
    }
}
