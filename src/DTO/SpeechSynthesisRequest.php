<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\DTO;

/**
 * Represents a request to a speech synthesis (TTS) driver.
 */
final readonly class SpeechSynthesisRequest
{
    /**
     * @param string      $text           Text to synthesize.
     * @param string|null $voice          Provider-specific voice identifier, null = provider default.
     * @param string      $format         Target audio container/codec (e.g. "mp3", "opus").
     * @param string|null $model
     * @param float|null  $timeoutSeconds Overrides the driver's default HTTP timeout for this request only.
     */
    public function __construct(
        public string $text,
        public ?string $voice = null,
        public string $format = 'mp3',
        public ?string $model = null,
        public ?float $timeoutSeconds = null,
    ) {}
}
