<?php

declare(strict_types=1);

namespace LlmRouter\DTO;

/**
 * Represents the response from an audio transcription driver.
 */
final readonly class AudioTranscriptionResponse
{
    /**
     * @param string      $text
     * @param string      $model
     * @param string|null $language        Detected or provided language, when the provider reports one.
     * @param float|null  $durationSeconds Audio duration, when the provider reports one.
     * @param float       $costUsd
     * @param int         $latencyMs
     * @param array<string, mixed> $metadata Driver-specific metadata.
     */
    public function __construct(
        public string $text,
        public string $model,
        public ?string $language,
        public ?float $durationSeconds,
        public float $costUsd,
        public int $latencyMs,
        public array $metadata = [],
    ) {}
}
