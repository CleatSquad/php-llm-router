<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\DTO;

/**
 * Represents a request to an image generation driver.
 */
final readonly class ImageGenerationRequest
{
    /**
     * @param string      $prompt         Text description of the image to generate.
     * @param string|null $model          Provider model id. Null lets the driver pick its default.
     * @param string|null $size           Provider-specific size string (e.g. "1024x1024"). Null lets the driver pick its default.
     * @param int         $count          Number of images to generate in one call.
     * @param float|null  $timeoutSeconds Overrides the driver's default HTTP timeout for this request only.
     */
    public function __construct(
        public string $prompt,
        public ?string $model = null,
        public ?string $size = null,
        public int $count = 1,
        public ?float $timeoutSeconds = null,
    ) {
    }
}
