<?php

declare(strict_types=1);

namespace LlmRouter\Driver\Concern;

/**
 * Shared by every driver whose provider needs its OWN wire shape for image
 * content, since the request DTO carries the caller's content in the one
 * shape OpenAI's Chat Completions API actually expects natively (`{type:
 * text, text}` / `{type: image_url, image_url: {url: "data:...;base64,..."}}`
 * — see LLMRequest::estimateInputTokens()'s own handling of that shape).
 * OpenAiDriver passes it straight through unmodified; every other
 * vision-capable driver needs to translate it into its provider's native
 * image block before sending — this trait does the provider-agnostic half
 * of that (parsing the message into plain text + decoded image parts), the
 * driver itself does the provider-specific half (building its own block
 * shape from those parts).
 */
trait NormalizesVisionContent
{
    /**
     * Splits a message's `content` (either a plain string, or an OpenAI-
     * shaped multi-part array) into its text and any embedded images.
     * A plain string returns as-is with no images — the shape every
     * driver already sent correctly for a text-only message, unchanged.
     * An `image_url` part whose `url` isn't a `data:` URI (unlikely for
     * this app's own callers, which always inline base64) is skipped
     * rather than guessed at — a provider-side fetch-by-URL is a
     * different code path this trait doesn't attempt to fake.
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
