<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tooling;

final class ModelDriftChecker
{
    /**
     * Generations this package deliberately does not catalogue.
     *
     * These are served and are genuine chat models, so the drift check is right to
     * see them — but they are superseded, and adding them would mean carrying
     * prices for models nobody should be starting new work on. Listing them here
     * says "considered and declined" instead of letting them resurface every week.
     * A caller who still needs one registers it through $extraModelPricing.
     *
     * @var string[]
     */
    public const DECLINED = [
        // Superseded OpenAI generations.
        'gpt-3.5', 'gpt-4-', 'gpt-4-0', 'gpt-4o-2024', 'gpt-4o-mini-2024',
        // Groq's compound systems orchestrate tools rather than serving tokens,
        // and Groq publishes no per-token rate for them — there is nothing to put
        // in a pricing table. allam-2-7b likewise has no published rate.
        'groq/compound', 'allam-',
        // Google's open-weight Gemma models are served through the Gemini API on
        // the free tier and carry no paid per-token rate.
        'gemma-',
    ];

    /**
     * Whether a served ID is a moving alias rather than a concrete model.
     *
     * `gemini-flash-lite-latest` and friends resolve to whichever version Google
     * currently points them at, so they carry no rate of their own — the versioned
     * model behind them does. Cataloguing one would mean quoting a price that
     * silently becomes wrong the day the alias moves, which is the failure this
     * whole exercise exists to prevent. Name the version you want instead.
     */
    public function isMovingAlias(string $id): bool
    {
        return str_ends_with($id, '-latest');
    }

    /**
     * Whether a served model ID is a chat model this package could plausibly use.
     */
    public function isChatModel(string $id): bool
    {
        foreach ([
            'embedding', 'embed-', 'tts-', 'whisper', 'moderation', 'dall-e', 'davinci',
            'babbage', 'realtime', 'transcribe', 'audio', 'image', 'guard', 'rerank',
            'speech', 'orpheus', 'playai', 'veo', 'imagen', 'aqa', 'learnlm',
            'search-preview', 'tts', 'codex', 'computer-use',
            // Mistral: voice models, and the labs- prefix marks experiments.
            'voxtral', 'labs-', 'ocr', 'moderation',
            // Gemini: previews, and modalities this package does not serve.
            '-preview', 'lyria', 'robotics', 'antigravity', 'deep-research',
            'nano-banana', 'omni',
        ] as $marker) {
            if (str_contains($id, $marker)) {
                return false;
            }
        }

        foreach (self::DECLINED as $declined) {
            if ($id === rtrim($declined, '-') || str_starts_with($id, $declined)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A catalogue entry is served if the provider lists it, or lists a dated
     * snapshot of it (`claude-haiku-4-5` ← `claude-haiku-4-5-20251001`).
     *
     * @param string[] $live
     */
    public function isServed(string $entry, array $live): bool
    {
        foreach ($live as $id) {
            if ($id === $entry || str_starts_with($id, $entry . '-')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The mirror of isServed(): whether a served ID is a dated snapshot of
     * something the catalogue already covers (`o1-2024-12-17` → `o1`).
     *
     * Both directions are needed, and getting them the wrong way round is easy —
     * the first run of this script reported 67 OpenAI snapshots as missing models
     * because this case was checked with isServed()'s comparison reversed.
     *
     * @param string[] $shipped
     */
    public function isSnapshotOfKnown(string $id, array $shipped): bool
    {
        foreach ($shipped as $entry) {
            if (str_starts_with($id, $entry . '-')) {
                return true;
            }

            // Mistral names its alias `mistral-medium-latest` and its snapshots
            // `mistral-medium-2505`; comparing on the stem matches the two.
            $stem = preg_replace('/-latest$/', '', $entry);
            if ($stem !== $entry && $stem !== null
                && ($id === $stem || str_starts_with($id, $stem . '-'))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $shipped Les model_id à vérifier pour ce fournisseur.
     * @param string[] $live    Ce que /models du fournisseur sert réellement.
     * @return array{retired: list<string>, missing: list<string>}
     */
    public function compare(array $shipped, array $live): array
    {
        // A shipped model the provider no longer lists is the dangerous case: it
        // stays selectable, and every call using it fails at the provider.
        $retired = array_values(array_filter(
            $shipped,
            fn (string $entry): bool => !$this->isServed($entry, $live)
        ));

        // A live model absent from the catalogue is only an opportunity — it can't
        // be added automatically, because its price isn't in this payload.
        $missing = array_values(array_filter(
            $live,
            fn (string $id): bool => $this->isChatModel($id)
                && !$this->isMovingAlias($id)
                && !in_array($id, $shipped, true)
                && !$this->isSnapshotOfKnown($id, $shipped)
        ));

        return [
            'retired' => $retired,
            'missing' => $missing,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchJson(string $url, string $header, int $timeout = 30): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [$header, 'anthropic-version: 2023-06-01'],
            CURLOPT_TIMEOUT => $timeout,
        ]);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!is_string($body) || $status !== 200) {
            return null;
        }

        $data = json_decode($body, true);

        return is_array($data) ? $data : null;
    }
}
