<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\DTO;

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
     * @param float|null  $avgLogprob      Whisper's average log-probability across segments —
     *                                     closer to 0 is confident, more negative (below ~-1)
     *                                     signals a garbled/uncertain transcription. Null when
     *                                     the provider doesn't report per-segment confidence.
     * @param float|null  $noSpeechProb    Whisper's probability that a segment was actually
     *                                     silence/noise rather than speech (0-1, worst segment).
     *                                     Null when the provider doesn't report it.
     */
    public function __construct(
        public string $text,
        public string $model,
        public ?string $language,
        public ?float $durationSeconds,
        public float $costUsd,
        public int $latencyMs,
        public array $metadata = [],
        public ?float $avgLogprob = null,
        public ?float $noSpeechProb = null,
    ) {}

    /**
     * Flags a transcription unreliable only when both signals are reported and both independently look bad — either alone can be a false positive (e.g. a short confident word naturally has a low avg_logprob).
     */
    public function isLowConfidence(): bool
    {
        if ($this->avgLogprob === null || $this->noSpeechProb === null) {
            return false;
        }

        return $this->avgLogprob < -1.0 && $this->noSpeechProb > 0.6;
    }
}
