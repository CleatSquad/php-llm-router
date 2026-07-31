<?php

declare(strict_types=1);

namespace LlmRouter\Contract\Driver;

use LlmRouter\DTO\AudioTranscriptionRequest;
use LlmRouter\DTO\AudioTranscriptionResponse;
use LlmRouter\DTO\CostEstimate;

interface AudioDriverInterface extends DriverInterface
{
    /**
     * Transcribe spoken audio to text.
     */
    public function transcribe(AudioTranscriptionRequest $request): AudioTranscriptionResponse;

    /**
     * Get the list of available model identifiers.
     *
     * @return string[]
     */
    public function getModels(): array;

    /**
     * Estimate the cost for a given request.
     */
    public function estimateCost(AudioTranscriptionRequest $request): CostEstimate;
}
