<?php

declare(strict_types=1);

namespace Concio\LlmRouter\DTO;

/**
 * Represents a request to an LLM driver.
 */
final readonly class LLMRequest
{
    /**
     * @param array<int, array{role: string, content: string}> $messages
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
    ) {}

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
