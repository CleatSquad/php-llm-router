<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Driver\Concern;

/**
 * Shared by vision-capable drivers that need their own wire shape for image content — request DTOs carry it in OpenAI's native shape (see LLMRequest::estimateInputTokens()), which OpenAiDriver passes through unmodified but every other driver must translate.
 * This trait does the provider-agnostic half — parsing into plain text + decoded image parts — each driver builds its own block shape from those parts.
 */
trait NormalizesVisionContent
{
    /**
     * Splits `content` (plain string or OpenAI-shaped multi-part array) into text and decoded images.
     * An `image_url` part whose `url` isn't a `data:` URI is skipped rather than fetched — callers always inline base64.
     *
     * @param mixed $content
     * @return array{0: string, 1: array<int, array{mediaType: string, data: string}>}
     */
    private function extractVisionParts(mixed $content): array
    {
        if (is_string($content)) {
            return [$content, []];
        }

        if (!is_array($content)) {
            return ['', []];
        }

        $text = '';
        $images = [];

        foreach ($content as $part) {
            $type = $part['type'] ?? null;

            if ($type === 'text') {
                $text .= (string) ($part['text'] ?? '');
            } elseif ($type === 'image_url') {
                $url = $part['image_url']['url'] ?? '';
                if (is_string($url) && preg_match('/^data:([^;]+);base64,(.+)$/s', $url, $matches) === 1) {
                    $images[] = ['mediaType' => $matches[1], 'data' => $matches[2]];
                }
            }
        }

        return [$text, $images];
    }
}
