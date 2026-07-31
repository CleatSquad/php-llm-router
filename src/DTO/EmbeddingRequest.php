<?php

declare(strict_types=1);

namespace LlmRouter\DTO;

/**
 * Represents a request to an embedding driver.
 */
final readonly class EmbeddingRequest
{
    /**
     * @param string[]    $input          One or more texts to embed. A single
     *   string is wrapped into a one-element array by the convenience
     *   EmbeddingRequest::forText() factory below.
     * @param string|null $model
     * @param float|null  $timeoutSeconds Overrides the driver's default HTTP timeout for this request only.
     */
    public function __construct(
        public array $input,
        public ?string $model = null,
        public ?float $timeoutSeconds = null,
    ) {}

    public static function forText(string $text, ?string $model = null): self
    {
        return new self([$text], $model);
    }
}
