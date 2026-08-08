<?php

declare(strict_types=1);

namespace LlmRouter\Tests\Fixtures;

use Generator;
use LlmRouter\Contract\Driver\LLMDriverInterface;
use LlmRouter\DTO\CostEstimate;
use LlmRouter\DTO\HealthStatus;
use LlmRouter\DTO\LLMRequest;
use LlmRouter\DTO\LLMResponse;
use LlmRouter\Enum\DriverType;
use Throwable;

/**
 * LLMDriverInterface stub whose stream() yields a scripted sequence of
 * chunks and then optionally fails — for tests that need a failure to
 * happen *after* some output already reached the caller, which
 * ControllableDriver (fails before yielding anything, or succeeds) can't
 * express.
 */
final class PartialStreamDriver implements LLMDriverInterface
{
    /**
     * @param string[] $chunks
     */
    public function __construct(
        private readonly string $id,
        private readonly array $chunks,
        private readonly ?Throwable $failure = null,
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

    public function chat(LLMRequest $request): LLMResponse
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new LLMResponse(
            content: implode('', $this->chunks),
            model: 'fake-model',
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
        foreach ($this->chunks as $chunk) {
            yield $chunk;
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

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
