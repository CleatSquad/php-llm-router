<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Contract\Driver;

use CleatSquad\LlmRouter\DTO\CostEstimate;
use CleatSquad\LlmRouter\DTO\EmbeddingRequest;
use CleatSquad\LlmRouter\DTO\EmbeddingResponse;

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
