<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Fixtures;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\DTO\CostEstimate;
use CleatSquad\LlmRouter\DTO\HealthStatus;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\DTO\LLMResponse;
use CleatSquad\LlmRouter\Enum\DriverType;
use Generator;

/**
 * Minimal in-memory LLMDriverInterface implementation for routing-strategy
 * tests — no network calls, availability is just a constructor flag.
 */
final class FakeDriver implements LLMDriverInterface
{
    public function __construct(
        private readonly string $id,
        private readonly bool $available = true,
        private readonly ?CostEstimate $costEstimate = null,
        private readonly bool $supportsTools = false,
        private readonly bool $supportsVision = false,
        private readonly bool $supportsReasoning = false,
        private readonly bool $supportsStreaming = true
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
        return $this->available;
    }

    public function healthCheck(): HealthStatus
    {
        return $this->available ? HealthStatus::healthy() : HealthStatus::unhealthy('fake driver unavailable');
    }

    public function getMetadata(): array
    {
        return [];
    }

    public function chat(LLMRequest $request): LLMResponse
    {
        return new LLMResponse(
            content: 'fake response from ' . $this->id,
            model: $request->model ?? 'fake-model',
            promptTokens: 0,
            completionTokens: 0,
            totalTokens: 0,
            costUsd: 0.0,
            latencyMs: 0,
            toolCalls: null,
            finishReason: 'stop'
        );
    }

    public function stream(LLMRequest $request): Generator
    {
        yield 'fake';
    }

    public function getModels(): array
    {
        return ['fake-model'];
    }

    public function supportsStreaming(): bool
    {
        return $this->supportsStreaming;
    }

    public function supportsTools(): bool
    {
        return $this->supportsTools;
    }

    public function supportsVision(): bool
    {
        return $this->supportsVision;
    }

    public function supportsReasoning(): bool
    {
        return $this->supportsReasoning;
    }

    public function estimateCost(LLMRequest $request): CostEstimate
    {
        return $this->costEstimate ?? CostEstimate::free();
    }
}
