<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Contract\Driver;

use CleatSquad\LlmRouter\DTO\CostEstimate;
use CleatSquad\LlmRouter\DTO\SpeechSynthesisRequest;
use CleatSquad\LlmRouter\DTO\SpeechSynthesisResponse;

interface SpeechSynthesisDriverInterface extends DriverInterface
{
    /**
     * Synthesize text into spoken audio.
     */
    public function synthesize(SpeechSynthesisRequest $request): SpeechSynthesisResponse;

    /**
     * Get the list of available model identifiers.
     *
     * @return string[]
     */
    public function getModels(): array;

    /**
     * Estimate the cost for a given request.
     */
    public function estimateCost(SpeechSynthesisRequest $request): CostEstimate;
}
