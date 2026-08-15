<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\DTO;

use CleatSquad\LlmRouter\Enum\ReasoningEffort;
use Closure;

/**
 * Represents a request to an LLM driver.
 */
final readonly class LLMRequest
{
    /**
     * @param array<int, array<string, mixed>> $messages Chat history. Beyond
     *   role/content, an assistant entry produced by LLMResponse::toMessage()
     *   may carry a `reasoning` trace, which drivers that require it replay to
     *   the provider in its native shape.
     * @param string|null $model
     * @param float|null $temperature
     * @param int|null $maxTokens
     * @param array<int, array<string, mixed>>|null $tools
     * @param bool $stream
     * @param float|null $timeoutSeconds Overrides the driver's default HTTP
     *   timeout for this request only. Useful for "any fast, cheap,
     *   capable-enough model will do" calls so a slow/overloaded driver
     *   fails fast enough for a routing strategy to fail over to another
     *   one, instead of blocking for the full timeout meant for the main
     *   reply.
     * @param bool $preferQuality Only consulted when model is null (so a
     *   quality-aware routing strategy is actually in play). Signals that
     *   reasoning/tool-use quality matters more than raw speed/cost for
     *   this request.
     * @param ReasoningEffort|null $reasoningEffort How hard the model should
     *   think before answering. Null leaves the provider's own default alone,
     *   which is what every request did before this parameter existed, so
     *   omitting it changes nothing. Drivers that cannot reason ignore it, and
     *   drivers supporting fewer levels clamp to their nearest one.
     * @param bool $includeReasoning Ask the provider to return the reasoning
     *   text, not merely to spend tokens on it. Off by default: it costs
     *   latency, and on Claude it is billed either way. OpenAI never returns
     *   its reasoning, so there this changes nothing.
     * @param Closure(string): void|null $onReasoning Receives each reasoning
     *   fragment while streaming. Reasoning never reaches the caller through
     *   the yielded values — those stay pure visible text, so existing loops
     *   keep working and no application accidentally prints a model's scratch
     *   work to a user. Requires $includeReasoning.
     */
    public function __construct(
        public array $messages,
        public ?string $model = null,
        public ?float $temperature = null,
        public ?int $maxTokens = null,
        public ?array $tools = null,
        public bool $stream = false,
        public ?float $timeoutSeconds = null,
        public bool $preferQuality = false,
        public ?ReasoningEffort $reasoningEffort = null,
        public bool $includeReasoning = false,
        public ?Closure $onReasoning = null,
    ) {}

    /**
     * Whether this request asks the model to think at all.
     */
    public function wantsReasoning(): bool
    {
        return $this->reasoningEffort !== null && $this->reasoningEffort !== ReasoningEffort::None;
    }

    /**
     * Hands a reasoning fragment to the caller's callback, when one was given.
     */
    public function emitReasoning(string $fragment): void
    {
        if ($fragment !== '' && $this->onReasoning !== null) {
            ($this->onReasoning)($fragment);
        }
    }

    /**
     * Estimate the total input token count (rough: 1 token ≈ 4 chars).
     */
    public function estimateInputTokens(): int
    {
        $chars = 0;
        foreach ($this->messages as $msg) {
            $content = $msg['content'] ?? '';
            if (is_array($content)) {
                foreach ($content as $part) {
                    if (isset($part['type']) && $part['type'] === 'text') {
                        $chars += mb_strlen($part['text'] ?? '');
                    } elseif (isset($part['type']) && $part['type'] === 'image_url') {
                        $chars += 1000; // approximation for vision tokens
                    }
                }
            } else {
                $chars += mb_strlen((string) $content);
            }
        }
        return (int) ceil($chars / 4);
    }
}
