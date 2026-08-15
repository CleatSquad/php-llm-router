<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Fixtures;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\Contract\Driver\ModelCatalogueInterface;
use CleatSquad\LlmRouter\DTO\CostEstimate;
use CleatSquad\LlmRouter\DTO\HealthStatus;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\DTO\LLMResponse;
use CleatSquad\LlmRouter\Enum\DriverType;
use CleatSquad\LlmRouter\Exception\UnknownModelException;
use Generator;
use Throwable;

/**
 * A driver that records the model name it was handed on every call, and
 * refuses anything outside its own catalogue — the two behaviours the
 * execution tests are actually about.
 *
 * The refusal matters as much as the recording: a fixture that accepts any
 * model would let a fallback receive the primary candidate's model and still
 * report success, which is precisely the bug these tests exist to catch.
 */
class RecordingDriver implements LLMDriverInterface, ModelCatalogueInterface
{
    /** @var string[] Model name seen on each chat()/stream() call, in order. */
    public array $receivedModels = [];

    public bool $available = true;

    private int $callCount = 0;

    /**
     * @param string[] $models This driver's catalogue.
     * @param array<int, Throwable|null> $outcomes null = success, Throwable = that call fails.
     */
    public function __construct(
        private readonly string $id,
        private readonly array $models,
        private readonly array $outcomes = [],
        private readonly bool $vision = false,
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
        return $this->available ? HealthStatus::healthy() : HealthStatus::unhealthy($this->id);
    }

    public function getMetadata(): array
    {
        return [];
    }

    public function supportsModel(string $model): bool
    {
        return in_array($model, $this->models, true);
    }

    public function getModels(): array
    {
        return $this->models;
    }

    private function accept(LLMRequest $request): string
    {
        $model = $request->model ?? $this->models[0];
        $this->receivedModels[] = $model;

        if (!$this->supportsModel($model)) {
            throw new UnknownModelException($this->id, $model, $this->models);
        }

        $outcome = $this->outcomes[$this->callCount] ?? null;
        $this->callCount++;

        if ($outcome !== null) {
            throw $outcome;
        }

        return $model;
    }

    public function chat(LLMRequest $request): LLMResponse
    {
        $model = $this->accept($request);

        return new LLMResponse(
            content: 'answered by ' . $this->id,
            model: $model,
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
        $this->accept($request);

        yield 'chunk from ' . $this->id;

        return null;
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
        return $this->vision;
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
