#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Compares each driver's shipped model catalogue against what the provider's
 * own /models endpoint currently serves, and reports the drift.
 *
 * Why this exists: v2.3.0 shipped after discovering that DeepSeek's and
 * Gemini's default models had been retired months earlier, and that Mistral
 * Large was priced at four times its real rate. Nothing in the test suite could
 * catch that — the tests asserted the wrong numbers just as confidently.
 *
 * What it checks, and what it deliberately does not:
 *
 *   Model existence — checked. Every provider here publishes a /models
 *   endpoint listing exactly what it will serve, so "this catalogue entry no
 *   longer exists" and "this model is new" are facts, not guesses.
 *
 *   Prices — NOT checked. No provider exposes pricing over its API; the
 *   numbers live in marketing pages whose markup changes without notice.
 *   A scraper would eventually write a plausible wrong price into the table,
 *   and a wrong price is worse than a stale one: estimateCost() would report
 *   it with total confidence. Price drift is therefore reported as "verify
 *   this by hand", never auto-corrected.
 *
 * Two sources of false alarm are filtered out, learned from the first run:
 *
 *   Aliases. /v1/models lists dated snapshots — `claude-haiku-4-5-20251001` —
 *   while catalogues store the stable alias `claude-haiku-4-5`. The alias is
 *   valid and serving; reporting it as retired would have removed a working
 *   model. An entry is only retired if no served ID starts with it.
 *
 *   Non-chat models. A provider's /models lists embeddings, speech, moderation
 *   and realtime endpoints too. These drivers serve chat, so anything else is
 *   noise — OpenAI alone contributed 122 lines of it on the first run, burying
 *   the real findings.
 *
 * Exit codes: 0 = no drift, 1 = drift found, 2 = could not check (no keys).
 */

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
const DECLINED = [
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
function isMovingAlias(string $id): bool
{
    return str_ends_with($id, '-latest');
}

/**
 * Whether a served model ID is a chat model this package could plausibly use.
 */
function isChatModel(string $id): bool
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

    foreach (DECLINED as $declined) {
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
function isServed(string $entry, array $live): bool
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
function isSnapshotOfKnown(string $id, array $shipped): bool
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

require __DIR__ . '/../vendor/autoload.php';

/**
 * Each provider: the driver whose catalogue to check, the endpoint listing its
 * models, the env var holding the key, and how to read IDs out of the payload.
 *
 * @var array<string, array{driver: class-string, url: string, env: string, header: string, extract: callable(array<string, mixed>): string[]}>
 */
$providers = [
    'Anthropic' => [
        'driver' => CleatSquad\LlmRouter\Driver\ClaudeDriver::class,
        'url' => 'https://api.anthropic.com/v1/models?limit=100',
        'env' => 'ANTHROPIC_API_KEY',
        'header' => 'x-api-key: %s',
        'extract' => static fn (array $d): array => array_column($d['data'] ?? [], 'id'),
    ],
    'OpenAI' => [
        'driver' => CleatSquad\LlmRouter\Driver\OpenAiDriver::class,
        'url' => 'https://api.openai.com/v1/models',
        'env' => 'OPENAI_API_KEY',
        'header' => 'Authorization: Bearer %s',
        'extract' => static fn (array $d): array => array_column($d['data'] ?? [], 'id'),
    ],
    'Groq' => [
        'driver' => CleatSquad\LlmRouter\Driver\GroqDriver::class,
        'url' => 'https://api.groq.com/openai/v1/models',
        'env' => 'GROQ_API_KEY',
        'header' => 'Authorization: Bearer %s',
        'extract' => static fn (array $d): array => array_column($d['data'] ?? [], 'id'),
    ],
    'Mistral' => [
        'driver' => CleatSquad\LlmRouter\Driver\MistralDriver::class,
        'url' => 'https://api.mistral.ai/v1/models',
        'env' => 'MISTRAL_API_KEY',
        'header' => 'Authorization: Bearer %s',
        'extract' => static fn (array $d): array => array_column($d['data'] ?? [], 'id'),
    ],
    'Kimi' => [
        'driver' => CleatSquad\LlmRouter\Driver\KimiDriver::class,
        // The international endpoint, whose catalogue is the one this package
        // prices in USD. The mainland host serves a different model list.
        'url' => 'https://api.moonshot.ai/v1/models',
        'env' => 'MOONSHOT_API_KEY',
        'header' => 'Authorization: Bearer %s',
        'extract' => static fn (array $d): array => array_column($d['data'] ?? [], 'id'),
    ],
    'DeepSeek' => [
        'driver' => CleatSquad\LlmRouter\Driver\DeepSeekDriver::class,
        'url' => 'https://api.deepseek.com/models',
        'env' => 'DEEPSEEK_API_KEY',
        'header' => 'Authorization: Bearer %s',
        'extract' => static fn (array $d): array => array_column($d['data'] ?? [], 'id'),
    ],
    'Gemini' => [
        'driver' => CleatSquad\LlmRouter\Driver\GeminiDriver::class,
        'url' => 'https://generativelanguage.googleapis.com/v1beta/models',
        'env' => 'GEMINI_API_KEY',
        'header' => 'x-goog-api-key: %s',
        // Gemini returns "models/gemini-2.5-flash"; the catalogue stores the
        // bare name.
        'extract' => static fn (array $d): array => array_map(
            static fn (array $m): string => str_replace('models/', '', (string) ($m['name'] ?? '')),
            $d['models'] ?? []
        ),
    ],
];

/**
 * @return array<string, mixed>|null
 */
function fetchJson(string $url, string $header): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [$header, 'anthropic-version: 2023-06-01'],
        CURLOPT_TIMEOUT => 30,
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

$checked = 0;
$findings = [];

foreach ($providers as $name => $provider) {
    $key = getenv($provider['env']);
    if (!is_string($key) || $key === '') {
        fwrite(STDERR, sprintf("· %s skipped — %s not set\n", $name, $provider['env']));
        continue;
    }

    $payload = fetchJson($provider['url'], sprintf($provider['header'], $key));
    if ($payload === null) {
        fwrite(STDERR, sprintf("! %s unreachable — leaving its catalogue alone\n", $name));
        continue;
    }

    $checked++;

    /** @var string[] $live */
    $live = array_filter($provider['extract']($payload));
    /** @var string[] $shipped */
    $shipped = (new $provider['driver'](new CleatSquad\LlmRouter\Http\HttpClient()))->getModels();

    // A shipped model the provider no longer lists is the dangerous case: it
    // stays selectable, and every call using it fails at the provider.
    $retired = array_values(array_filter(
        $shipped,
        static fn (string $entry): bool => !isServed($entry, $live)
    ));

    // A live model absent from the catalogue is only an opportunity — it can't
    // be added automatically, because its price isn't in this payload.
    $missing = array_values(array_filter(
        $live,
        static fn (string $id): bool => isChatModel($id)
            && !isMovingAlias($id)
            && !in_array($id, $shipped, true)
            && !isSnapshotOfKnown($id, $shipped)
    ));

    if ($retired !== []) {
        $findings[] = sprintf(
            "### %s — %d catalogue %s no longer served\n\n%s\n\n"
                . "These are still selectable through the driver, so any call naming one fails at the provider. "
                . "If one is a `DEFAULT_MODEL`, every call that names no model fails.\n\n"
                . "_A model restricted to your account tier (a preview or invite-only model) also shows up here — "
                . "check before removing._",
            $name,
            count($retired),
            count($retired) === 1 ? 'entry' : 'entries',
            implode("\n", array_map(static fn (string $m): string => "- `$m`", $retired))
        );
    }

    if ($missing !== []) {
        $findings[] = sprintf(
            "### %s — %d model%s served but not in the catalogue\n\n%s\n\n"
                . "**Adding these needs a human**: the /models endpoint carries no pricing, and a guessed rate "
                . "would make `estimateCost()` confidently wrong. Look the rates up, then add them.",
            $name,
            count($missing),
            count($missing) === 1 ? '' : 's',
            implode("\n", array_map(static fn (string $m): string => "- `$m`", array_slice($missing, 0, 25)))
        );
    }
}

if ($checked === 0) {
    fwrite(STDERR, "\nNo provider could be checked — set at least one API key.\n");
    exit(2);
}

if ($findings === []) {
    fwrite(STDOUT, sprintf("\nNo drift: %d provider catalogue(s) match what is served.\n", $checked));
    exit(0);
}

fwrite(STDOUT, implode("\n\n", $findings) . "\n");
exit(1);
