<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\DTO;

/**
 * Represents a request to an audio transcription driver.
 */
final readonly class AudioTranscriptionRequest
{
    /**
     * @param string      $audioContent   Raw audio bytes (any format the provider accepts — mp3/mp4/wav/ogg/webm/m4a...).
     * @param string      $filename       Sent alongside the bytes so the provider can infer the format from the extension.
     * @param string|null $model
     * @param string|null $language       ISO-639-1 hint (e.g. "fr", "en") — improves accuracy, not required.
     * @param float|null  $timeoutSeconds Overrides the driver's default HTTP timeout for this request only.
     */
    public function __construct(
        public string $audioContent,
        public string $filename = 'audio.ogg',
        public ?string $model = null,
        public ?string $language = null,
        public ?float $timeoutSeconds = null,
    ) {}

    public static function fromFile(string $path, ?string $model = null, ?string $language = null): self
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('Could not read audio file: ' . $path);
        }

        return new self($content, basename($path), $model, $language);
    }
}
