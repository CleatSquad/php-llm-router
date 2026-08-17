<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Driver;

use CleatSquad\LlmRouter\CircuitBreaker\InMemoryCircuitBreakerStore;
use CleatSquad\LlmRouter\Driver\CircuitBreakerDriver;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Tests\Fixtures\ControllableDriver;
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
        $rateLimitFailure = new \CleatSquad\LlmRouter\Exception\RateLimitException('Rate limit hit', 3442, 429);
        $inner = new ControllableDriver('fake', [$rateLimitFailure]);
        $store = new InMemoryCircuitBreakerStore();
        $breaker = new CircuitBreakerDriver($inner, $store, failureThreshold: 1, openSeconds: 60);

        try {
            $breaker->chat($this->request());
        } catch (\CleatSquad\LlmRouter\Exception\RateLimitException) {
        }

        $state = $store->getState('fake');
        $this->assertNotNull($state->openUntil);
        $diff = $state->openUntil->getTimestamp() - (new \DateTimeImmutable())->getTimestamp();
        $this->assertGreaterThanOrEqual(3440, $diff);
        $this->assertLessThanOrEqual(3445, $diff);
    }

    public function testImplementsModelCatalogueAndDelegatesSupportsModel(): void
    {
        $innerWithCatalogue = new class implements \CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface, \CleatSquad\LlmRouter\Contract\Driver\ModelCatalogueInterface {
            public function getId(): string { return 'mock'; }
            public function getName(): string { return 'Mock'; }
            public function getType(): \CleatSquad\LlmRouter\Enum\DriverType { return \CleatSquad\LlmRouter\Enum\DriverType::LLM; }
            public function isAvailable(): bool { return true; }
            public function healthCheck(): \CleatSquad\LlmRouter\DTO\HealthStatus { return \CleatSquad\LlmRouter\DTO\HealthStatus::healthy(); }
            public function getMetadata(): array { return []; }
            public function chat(\CleatSquad\LlmRouter\DTO\LLMRequest $request): \CleatSquad\LlmRouter\DTO\LLMResponse { throw new \RuntimeException('unused'); }
            public function stream(\CleatSquad\LlmRouter\DTO\LLMRequest $request): \Generator { yield 'unused'; return null; }
            public function getModels(): array { return ['model-a']; }
            public function supportsStreaming(): bool { return true; }
            public function supportsTools(): bool { return false; }
            public function supportsVision(): bool { return false; }
            public function supportsReasoning(): bool { return false; }
            public function estimateCost(\CleatSquad\LlmRouter\DTO\LLMRequest $request): \CleatSquad\LlmRouter\DTO\CostEstimate { return \CleatSquad\LlmRouter\DTO\CostEstimate::free(); }
            public function supportsModel(string $model): bool { return $model === 'model-a'; }
        };

        $breaker = new CircuitBreakerDriver($innerWithCatalogue);
        $this->assertInstanceOf(\CleatSquad\LlmRouter\Contract\Driver\ModelCatalogueInterface::class, $breaker);
        $this->assertTrue($breaker->supportsModel('model-a'));
        $this->assertFalse($breaker->supportsModel('model-b'));

        $innerWithoutCatalogue = new ControllableDriver('bare');
        $breakerBare = new CircuitBreakerDriver($innerWithoutCatalogue);
        $this->assertInstanceOf(\CleatSquad\LlmRouter\Contract\Driver\ModelCatalogueInterface::class, $breakerBare);
        $this->assertTrue($breakerBare->supportsModel('any-model'), 'Driver without ModelCatalogueInterface is assumed able to serve what it is given');
    }
}
