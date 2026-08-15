<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\DTO;

/**
 * Represents the response from an embedding driver.
 */
final readonly class EmbeddingResponse
{
    /**
     * @param array<int, array<int, float>> $embeddings One vector per input text, same order as the request.
     * @param string $model
     * @param int    $promptTokens
     * @param int    $totalTokens
     * @param float  $costUsd
     * @param int    $latencyMs
     * @param array<string, mixed> $metadata Driver-specific metadata.
     */
    public function __construct(
        public array $embeddings,
        public string $model,
        public int $promptTokens,
        public int $totalTokens,
        public float $costUsd,
        public int $latencyMs,
        public array $metadata = [],
    ) {}

    /**
     * Convenience accessor for single-input requests.
     *
     * @return array<int, float>
     */
    public function first(): array
    {
        return $this->embeddings[0] ?? [];
    }
}
