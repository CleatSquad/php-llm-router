<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\DTO;

/**
 * Represents the response from a speech synthesis (TTS) driver.
 */
final readonly class SpeechSynthesisResponse
{
    /**
     * @param string $audioContent Raw synthesized audio bytes.
     * @param string $format       Audio container/codec of $audioContent (e.g. "mp3").
     * @param string $model
     * @param float  $costUsd
     * @param int    $latencyMs
     */
    public function __construct(
        public string $audioContent,
        public string $format,
        public string $model,
        public float $costUsd,
        public int $latencyMs,
    ) {}
}
