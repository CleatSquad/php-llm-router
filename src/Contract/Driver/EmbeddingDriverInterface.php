<?php

declare(strict_types=1);

namespace LlmRouter\Contract\Driver;

use LlmRouter\DTO\CostEstimate;
use LlmRouter\DTO\EmbeddingRequest;
use LlmRouter\DTO\EmbeddingResponse;

interface EmbeddingDriverInterface extends DriverInterface
{
    /**
     * Embed one or more texts.
     */
    public function embed(EmbeddingRequest $request): EmbeddingResponse;

    /**
     * Get the list of available model identifiers.
     *
     * @return string[]
     */
    public function getModels(): array;

    /**
     * Estimate the cost for a given request.
     */
    public function estimateCost(EmbeddingRequest $request): CostEstimate;
}
