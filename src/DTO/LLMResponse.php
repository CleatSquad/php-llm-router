<?php

declare(strict_types=1);

namespace LlmRouter\DTO;

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
     * @param string|null $reasoning  The model's reasoning trace, when the
     *   request asked for it and the provider returns one. Null means the
     *   model didn't reason, wasn't asked to, or — as with OpenAI — keeps its
     *   reasoning private and only bills for it.
     * @param int|null $reasoningTokens  Output tokens spent on reasoning,
     *   where the provider reports the breakdown. These are already part of
     *   $completionTokens; this says how much of it was thinking.
     * @param string|null $reasoningSignature  Opaque provider token that
     *   authenticates the trace when it is replayed on the next turn. Never
     *   interpret it; pass it back untouched via toMessage().
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
        public ?string $reasoning = null,
        public ?int $reasoningTokens = null,
        public ?string $reasoningSignature = null,
    ) {}

    /**
     * Whether a reasoning trace came back with this response.
     */
    public function hasReasoning(): bool
    {
        return $this->reasoning !== null && $this->reasoning !== '';
    }

    /**
     * This response as a history entry to append to the next request's messages.
     *
     * Use this rather than hand-building `['role' => 'assistant', 'content' => ...]`
     * whenever reasoning is in play. Anthropic, Mistral and Moonshot all require
     * the reasoning trace to be replayed on the following turn — Moonshot is
     * explicit that dropping it during a tool-calling loop degrades the model —
     * and each expects it in a different native shape. Carrying it here lets the
     * driver re-emit it correctly, so callers don't have to learn three formats
     * or even know the requirement exists.
     *
     * @return array<string, mixed>
     */
    public function toMessage(): array
    {
        $message = [
            'role' => 'assistant',
            'content' => $this->content,
        ];

        if ($this->hasToolCalls()) {
            $message['tool_calls'] = $this->toolCalls;
        }

        if ($this->hasReasoning()) {
            $message['reasoning'] = $this->reasoning;
        }

        if ($this->reasoningSignature !== null) {
            $message['reasoning_signature'] = $this->reasoningSignature;
        }

        return $message;
    }

    /**
     * Check if the response contains tool calls that need execution.
     */
    public function hasToolCalls(): bool
    {
        return !empty($this->toolCalls);
    }
}
