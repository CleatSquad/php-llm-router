<?php

declare(strict_types=1);

namespace LlmRouter\Driver;

use LlmRouter\Contract\Driver\LLMDriverInterface;
use LlmRouter\DTO\CostEstimate;
use LlmRouter\DTO\HealthStatus;
use LlmRouter\DTO\LLMRequest;
use LlmRouter\DTO\LLMResponse;
use LlmRouter\Enum\DriverType;
use LlmRouter\RateLimit\InMemoryRateLimitStore;
use LlmRouter\RateLimit\RateLimitStoreInterface;
use LlmRouter\RateLimit\RateLimitWindow;
use DateTimeImmutable;
use Generator;
use RuntimeException;

/**
 * Decorates any LLMDriverInterface with a requests-per-minute and/or
 * tokens-per-minute budget. When a call would exceed either limit,
 * chat()/stream() block (polling) until capacity frees up or
 * $maxWaitSeconds runs out — whichever comes first — instead of firing
 * the request straight into the provider's own 429.
 *
 * Token usage for stream() is only an estimate: this package's
 * stream() yields text + an optional tool-calls array, no usage block
 * (providers don't send one over SSE), so only the request's estimated
 * input tokens count against the TPM budget for streamed calls — a
 * conservative undercount, not exact accounting.
 *
 * State is delegated to a RateLimitStoreInterface (defaults to
 * InMemoryRateLimitStore, scoped to the current process); implement it
 * against Redis/DB to share a quota across requests or processes.
 */
final class RateLimitedDriver implements LLMDriverInterface
{
    private RateLimitStoreInterface $store;

    public function __construct(
        private readonly LLMDriverInterface $inner,
        ?RateLimitStoreInterface $store = null,
        private readonly ?int $maxRequestsPerMinute = null,
        private readonly ?int $maxTokensPerMinute = null,
        private readonly int $windowSeconds = 60,
        private readonly float $maxWaitSeconds = 30.0,
        private readonly float $pollIntervalSeconds = 0.25,
    ) {
        $this->store = $store ?? new InMemoryRateLimitStore();
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
        return $this->inner->isAvailable();
    }

    public function healthCheck(): HealthStatus
    {
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
        $this->waitForCapacity();
        $response = $this->inner->chat($request);
        $this->recordUsage($response->promptTokens + $response->completionTokens);
        return $response;
    }

    /**
     * @return Generator<int, string, mixed, ?array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>>
     */
    public function stream(LLMRequest $request): Generator
    {
        $this->waitForCapacity();
        $toolCalls = yield from $this->inner->stream($request);
        $this->recordUsage($request->estimateInputTokens());
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

    private function waitForCapacity(): void
    {
        $elapsed = 0.0;
        while (!$this->hasCapacity()) {
            if ($elapsed >= $this->maxWaitSeconds) {
                throw new RuntimeException(sprintf(
                    'Rate limit exceeded for driver "%s" and the %.1fs wait budget ran out',
                    $this->inner->getId(),
                    $this->maxWaitSeconds
                ));
            }
            $this->sleep($this->pollIntervalSeconds);
            $elapsed += $this->pollIntervalSeconds;
        }
    }

    private function hasCapacity(): bool
    {
        $window = $this->currentWindow();
        $requestsOk = $this->maxRequestsPerMinute === null || $window->requestCount < $this->maxRequestsPerMinute;
        $tokensOk = $this->maxTokensPerMinute === null || $window->tokenCount < $this->maxTokensPerMinute;
        return $requestsOk && $tokensOk;
    }

    private function currentWindow(): RateLimitWindow
    {
        $id = $this->inner->getId();
        $now = new DateTimeImmutable();
        $window = $this->store->getWindow($id);

        if ($window === null || $window->isExpired($now, $this->windowSeconds)) {
            $window = RateLimitWindow::startingAt($now);
            $this->store->saveWindow($id, $window);
        }

        return $window;
    }

    private function recordUsage(int $tokens): void
    {
        $id = $this->inner->getId();
        $this->store->saveWindow($id, $this->currentWindow()->withUsage($tokens));
    }

    private function sleep(float $seconds): void
    {
        usleep((int) ($seconds * 1_000_000));
    }
}
