<?php

declare(strict_types=1);

namespace LlmRouter\Driver;

use LlmRouter\CircuitBreaker\CircuitBreakerStoreInterface;
use LlmRouter\CircuitBreaker\InMemoryCircuitBreakerStore;
use LlmRouter\Contract\Driver\LLMDriverInterface;
use LlmRouter\DTO\CostEstimate;
use LlmRouter\DTO\HealthStatus;
use LlmRouter\DTO\LLMRequest;
use LlmRouter\DTO\LLMResponse;
use LlmRouter\Enum\DriverType;
use DateTimeImmutable;
use Generator;
use RuntimeException;
use Throwable;

/**
 * Decorates a driver with a circuit breaker: after $failureThreshold consecutive failures it fails fast (no network call) for $openSeconds instead of every caller re-discovering the same outage; a single success resets the count.
 */
final class CircuitBreakerDriver implements LLMDriverInterface
{
    private CircuitBreakerStoreInterface $store;

    public function __construct(
        private readonly LLMDriverInterface $inner,
        ?CircuitBreakerStoreInterface $store = null,
        private readonly int $failureThreshold = 5,
        private readonly int $openSeconds = 60,
    ) {
        $this->store = $store ?? new InMemoryCircuitBreakerStore();
    }

    public function getId(): string
    {
        return $this->inner->getId();
    }

    public function getName(): string
    {
        return $this->inner->getName();
    }

    public function getType(): DriverType
    {
        return $this->inner->getType();
    }

    public function isAvailable(): bool
    {
        return !$this->isOpen() && $this->inner->isAvailable();
    }

    public function healthCheck(): HealthStatus
    {
        if ($this->isOpen()) {
            return HealthStatus::unhealthy(sprintf(
                'Circuit breaker open for "%s" — too many recent failures',
                $this->inner->getId()
            ));
        }

        return $this->inner->healthCheck();
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->inner->getMetadata();
    }

    public function chat(LLMRequest $request): LLMResponse
    {
        $this->assertClosed();

        try {
            $response = $this->inner->chat($request);
        } catch (Throwable $e) {
            $this->recordFailure();
            throw $e;
        }

        $this->recordSuccess();
        return $response;
    }

    /**
     * @return Generator<int, string, mixed, ?array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>>
     */
    public function stream(LLMRequest $request): Generator
    {
        $this->assertClosed();

        try {
            $toolCalls = yield from $this->inner->stream($request);
        } catch (Throwable $e) {
            $this->recordFailure();
            throw $e;
        }

        $this->recordSuccess();
        return $toolCalls;
    }

    /**
     * @return string[]
     */
    public function getModels(): array
    {
        return $this->inner->getModels();
    }

    public function supportsStreaming(): bool
    {
        return $this->inner->supportsStreaming();
    }

    public function supportsTools(): bool
    {
        return $this->inner->supportsTools();
    }

    public function supportsVision(): bool
    {
        return $this->inner->supportsVision();
    }

    public function supportsReasoning(): bool
    {
        return $this->inner->supportsReasoning();
    }

    public function estimateCost(LLMRequest $request): CostEstimate
    {
        return $this->inner->estimateCost($request);
    }

    private function isOpen(): bool
    {
        return $this->store->getState($this->inner->getId())->isOpen(new DateTimeImmutable());
    }

    private function assertClosed(): void
    {
        if ($this->isOpen()) {
            throw new RuntimeException(sprintf(
                'Circuit breaker open for driver "%s" — too many recent failures, retry later',
                $this->inner->getId()
            ));
        }
    }

    private function recordFailure(): void
    {
        $id = $this->inner->getId();
        $this->store->saveState(
            $id,
            $this->store->getState($id)->withFailure($this->failureThreshold, $this->openSeconds, new DateTimeImmutable())
        );
    }

    private function recordSuccess(): void
    {
        $id = $this->inner->getId();
        $this->store->saveState($id, $this->store->getState($id)->reset());
    }
}
