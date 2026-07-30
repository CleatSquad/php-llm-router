<?php

declare(strict_types=1);

namespace LlmRouter\Driver;

use LlmRouter\Cache\CacheStoreInterface;
use LlmRouter\Cache\InMemoryCacheStore;
use LlmRouter\Contract\Driver\LLMDriverInterface;
use LlmRouter\DTO\CostEstimate;
use LlmRouter\DTO\HealthStatus;
use LlmRouter\DTO\LLMRequest;
use LlmRouter\DTO\LLMResponse;
use LlmRouter\Enum\DriverType;
use Generator;

/**
 * Decorates any LLMDriverInterface with a response cache for chat():
 * an identical request (same messages/model/temperature/maxTokens/
 * tools/preferQuality) within the TTL window returns the previous
 * LLMResponse instead of paying for another call.
 *
 * stream() intentionally bypasses the cache — buffering a whole
 * response before the first byte reaches the caller would defeat the
 * point of streaming, and always delegates straight to the inner driver.
 *
 * State is delegated to a CacheStoreInterface (defaults to
 * InMemoryCacheStore, scoped to the current process); implement it
 * against Redis/DB to share the cache across requests or processes.
 */
final class CachingDriver implements LLMDriverInterface
{
    private CacheStoreInterface $store;

    public function __construct(
        private readonly LLMDriverInterface $inner,
        ?CacheStoreInterface $store = null,
        private readonly int $ttlSeconds = 300,
    ) {
        $this->store = $store ?? new InMemoryCacheStore();
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
        $key = $this->cacheKey($request);

        $cached = $this->store->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $response = $this->inner->chat($request);
        $this->store->set($key, $response, $this->ttlSeconds);

        return $response;
    }

    /**
     * @return Generator<int, string, mixed, ?array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>>
     */
    public function stream(LLMRequest $request): Generator
    {
        return yield from $this->inner->stream($request);
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

    private function cacheKey(LLMRequest $request): string
    {
        return hash('sha256', json_encode([
            $request->messages,
            $request->model,
            $request->temperature,
            $request->maxTokens,
            $request->tools,
            $request->preferQuality,
        ], JSON_THROW_ON_ERROR));
    }
}
