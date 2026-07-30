<?php

declare(strict_types=1);

namespace LlmRouter\DTO;

/**
 * Represents a cost estimate for an LLM request.
 */
final readonly class CostEstimate
{
    /**
     * @param float $inputCostPer1k   Cost per 1,000 input tokens in USD
     * @param float $outputCostPer1k  Cost per 1,000 output tokens in USD
     * @param int   $estimatedTokens  Estimated total tokens for the request
     * @param float $estimatedCostUsd Estimated total cost in USD
     */
    public function __construct(
        public float $inputCostPer1k,
        public float $outputCostPer1k,
        public int $estimatedTokens,
        public float $estimatedCostUsd,
    ) {}

    /**
     * Create a zero-cost estimate.
     */
    public static function free(): self
    {
        return new self(0.0, 0.0, 0, 0.0);
    }
}
