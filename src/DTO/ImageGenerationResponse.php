<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\DTO;

/**
 * Represents the response from an image generation driver.
 */
final readonly class ImageGenerationResponse
{
    /**
     * @param string[] $urls        Provider-hosted URLs for the generated images. Empty when the
     *                               provider returns inline data instead (see $base64Images).
     * @param string[] $base64Images Base64-encoded image data, when the provider returns inline
     *                               data instead of URLs. Empty when $urls is populated.
     * @param string   $model
     * @param float    $costUsd
     * @param int      $latencyMs
     */
    public function __construct(
        public array $urls,
        public array $base64Images,
        public string $model,
        public float $costUsd,
        public int $latencyMs,
    ) {
    }
}
