<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Contract\Driver;

use CleatSquad\LlmRouter\DTO\CostEstimate;
use CleatSquad\LlmRouter\DTO\ImageGenerationRequest;
use CleatSquad\LlmRouter\DTO\ImageGenerationResponse;

/**
 * A driver that generates images from a text prompt — same family
 * shape as AudioDriverInterface/SpeechSynthesisDriverInterface: a distinct
 * contract per modality, not a method bolted onto LLMDriverInterface.
 */
interface ImageGenerationDriverInterface extends DriverInterface
{
    public function generate(ImageGenerationRequest $request): ImageGenerationResponse;

    /**
     * @return string[]
     */
    public function getModels(): array;

    public function estimateCost(ImageGenerationRequest $request): CostEstimate;
}
