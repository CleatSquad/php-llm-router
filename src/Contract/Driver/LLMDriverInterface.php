<?php

declare(strict_types=1);

namespace LlmRouter\Contract\Driver;

use LlmRouter\DTO\CostEstimate;
use LlmRouter\DTO\LLMRequest;
use LlmRouter\DTO\LLMResponse;
use Generator;

interface LLMDriverInterface extends DriverInterface
{
    /**
     * Send a chat request to the LLM.
     */
    public function chat(LLMRequest $request): LLMResponse;

    /**
     * Stream a chat request response from the LLM — yields text content
     * fragments as they arrive. Once the generator is exhausted,
     * Generator::getReturn() carries any tool calls the model made during
     * the response (same {id, type: "function", function: {name,
     * arguments}} shape LLMResponse::$toolCalls uses), accumulated from
     * the provider's incremental tool-call deltas — or null if the model
     * didn't call any tool this turn (including drivers, like Ollama's,
     * that never support tool calls at all).
     *
     * @return Generator<int, string, mixed, ?array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>>
     */
    public function stream(LLMRequest $request): Generator;

    /**
     * Get the list of available model identifiers.
     *
     * @return string[]
     */
    public function getModels(): array;

    /**
     * Determine if streaming is supported by this driver.
     */
    public function supportsStreaming(): bool;

    /**
     * Determine if tool/function calling is supported by this driver.
     */
    public function supportsTools(): bool;

    /**
     * Determine if multimodal vision features are supported by this driver.
     */
    public function supportsVision(): bool;

    /**
     * Determine if extended reasoning/thinking is supported by this driver.
     */
    public function supportsReasoning(): bool;

    /**
     * Estimate the cost for a given request.
     */
    public function estimateCost(LLMRequest $request): CostEstimate;
}
