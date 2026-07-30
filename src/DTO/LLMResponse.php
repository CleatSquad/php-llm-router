<?php

declare(strict_types=1);

namespace Concio\LlmRouter\DTO;

/**
 * Represents the response from an LLM driver.
 */
final readonly class LLMResponse
{
    /**
     * @param string      $content           The text content of the response
     * @param string      $model             The model that generated the response
     * @param int         $promptTokens      Number of tokens in the prompt
     * @param int         $completionTokens  Number of tokens in the completion
     * @param int         $totalTokens       Total tokens (prompt + completion)
     * @param float       $costUsd           Estimated cost in USD
     * @param int         $latencyMs         Response latency in milliseconds
     * @param array<int, array<string, mixed>>|null $toolCalls  Tool calls requested by the LLM
     * @param string      $finishReason      Reason the generation stopped
     * @param array<string, mixed> $metadata  Driver-specific metadata (raw headers, cache info, etc.)
     */
    public function __construct(
        public string $content,
        public string $model,
        public int $promptTokens,
        public int $completionTokens,
        public int $totalTokens,
        public float $costUsd,
        public int $latencyMs,
        public ?array $toolCalls,
        public string $finishReason,
        public array $metadata = [],
    ) {}

    /**
     * Check if the response contains tool calls that need execution.
     */
    public function hasToolCalls(): bool
    {
        return !empty($this->toolCalls);
    }
}
