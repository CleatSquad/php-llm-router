<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\DTO;

use CleatSquad\LlmRouter\DTO\AudioTranscriptionResponse;
use PHPUnit\Framework\TestCase;

final class AudioTranscriptionResponseTest extends TestCase
{
    public function testIsLowConfidenceRequiresBothSignalsToLookBad(): void
    {
        $bothBad = new AudioTranscriptionResponse(
            'insurtement', 'whisper-1', 'fr', 1.0, 0.0001, 5,
            avgLogprob: -1.6, noSpeechProb: 0.8
        );
        $this->assertTrue($bothBad->isLowConfidence());

        // Confidently spoken short word: legitimately negative avg_logprob,
        // but clearly speech (low no_speech_prob) — shouldn't trip alone.
        $onlyLogprobBad = new AudioTranscriptionResponse(
            'ok', 'whisper-1', 'fr', 1.0, 0.0001, 5,
            avgLogprob: -1.6, noSpeechProb: 0.1
        );
        $this->assertFalse($onlyLogprobBad->isLowConfidence());

        // Background noise inflating no_speech_prob on an otherwise clear clip.
        $onlyNoSpeechBad = new AudioTranscriptionResponse(
            'bonjour', 'whisper-1', 'fr', 1.0, 0.0001, 5,
            avgLogprob: -0.2, noSpeechProb: 0.8
        );
        $this->assertFalse($onlyNoSpeechBad->isLowConfidence());
    }

    public function testIsLowConfidenceIsFalseWhenSignalsAreMissing(): void
    {
        $response = new AudioTranscriptionResponse('bonjour', 'whisper-1', 'fr', 1.0, 0.0001, 5);
        $this->assertFalse($response->isLowConfidence());
    }
}
