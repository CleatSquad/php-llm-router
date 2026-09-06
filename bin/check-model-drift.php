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
 * Exit codes: 0 = no drift, 1 = drift found, 2 = could not check (no keys).
 */

require __DIR__ . '/../vendor/autoload.php';

use CleatSquad\LlmRouter\Http\HttpClient;
use CleatSquad\LlmRouter\Tooling\ModelDriftChecker;

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

$checker = new ModelDriftChecker();
$checked = 0;
$findings = [];

foreach ($providers as $name => $provider) {
    $key = getenv($provider['env']);
    if (!is_string($key) || $key === '') {
        fwrite(STDERR, sprintf("· %s skipped — %s not set\n", $name, $provider['env']));
        continue;
    }

    $payload = $checker->fetchJson($provider['url'], sprintf($provider['header'], $key));
    if ($payload === null) {
        fwrite(STDERR, sprintf("! %s unreachable — leaving its catalogue alone\n", $name));
        continue;
    }

    $checked++;

    /** @var string[] $live */
    $live = array_filter($provider['extract']($payload));
    /** @var string[] $shipped */
    $shipped = new $provider['driver'](new HttpClient())->getModels();

    $result = $checker->compare($shipped, $live);
    $retired = $result['retired'];
    $missing = $result['missing'];

    if ($retired !== []) {
        $findings[] = sprintf(
            "### %s — %d catalogue %s no longer served\n\n%s\n\n"
                . 'These are still selectable through the driver, so any call naming one fails at the provider. '
                . "If one is a `DEFAULT_MODEL`, every call that names no model fails.\n\n"
                . '_A model restricted to your account tier (a preview or invite-only model) also shows up here — '
                . 'check before removing._',
            $name,
            count($retired),
            count($retired) === 1 ? 'entry' : 'entries',
            implode("\n", array_map(static fn (string $m): string => "- `$m`", $retired))
        );
    }

    if ($missing !== []) {
        $findings[] = sprintf(
            "### %s — %d model%s served but not in the catalogue\n\n%s\n\n"
                . '**Adding these needs a human**: the /models endpoint carries no pricing, and a guessed rate '
                . 'would make `estimateCost()` confidently wrong. Look the rates up, then add them.',
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
