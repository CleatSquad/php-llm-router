<?php

declare(strict_types=1);

namespace LlmRouter\Tests\Fixtures;

use LlmRouter\Contract\Driver\LLMDriverInterface;
use LlmRouter\DTO\CostEstimate;
use LlmRouter\DTO\HealthStatus;
use LlmRouter\DTO\LLMRequest;
use LlmRouter\DTO\LLMResponse;
use LlmRouter\Enum\DriverType;
use Generator;
use Throwable;

/**
 * LLMDriverInterface stub whose chat()/stream() outcomes are scripted
 * in advance — a queue of null (success) or Throwable (that call
 * fails) — for circuit-breaker tests that need to force N consecutive
 * failures then a recovery.
 */
final class ControllableDriver implements LLMDriverInterface
{
    public int $callCount = 0;

    /**
     * @param array<int, Throwable|null> $outcomes null = success, Throwable = that call fails
     */
    public function __construct(
        private readonly string $id,
        private readonly array $outcomes = [],
        private readonly int $completionTokens = 0
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->id;
    }

    public function getType(): DriverType
    {
        return DriverType::LLM;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function healthCheck(): HealthStatus
    {
        return HealthStatus::healthy();
    }

    public function getMetadata(): array
    {
        return [];
    }

    private function nextOutcome(): ?Throwable
    {
        $outcome = $this->outcomes[$this->callCount] ?? null;
        $this->callCount++;
        return $outcome;
    }

    public function chat(LLMRequest $request): LLMResponse
    {
        $outcome = $this->nextOutcome();
        if ($outcome !== null) {
            throw $outcome;
        }

        return new LLMResponse(
            content: 'ok',
            model: 'fake-model',
            promptTokens: 0,
            completionTokens: $this->completionTokens,
            totalTokens: $this->completionTokens,
            costUsd: 0.0,
            latencyMs: 0,
            toolCalls: null,
            finishReason: 'stop'
        );
    }

    public function stream(LLMRequest $request): Generator
    {
        $outcome = $this->nextOutcome();
        if ($outcome !== null) {
            throw $outcome;
        }

        yield 'ok';
        return null;
    }

    public function getModels(): array
    {
        return ['fake-model'];
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    public function supportsTools(): bool
    {
        return false;
    }

    public function supportsVision(): bool
    {
        return false;
    }

    public function supportsReasoning(): bool
    {
        return false;
    }

    public function estimateCost(LLMRequest $request): CostEstimate
    {
        return CostEstimate::free();
    }
}
