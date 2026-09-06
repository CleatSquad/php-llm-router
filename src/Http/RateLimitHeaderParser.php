<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Http;

use DateTimeImmutable;
use Throwable;

final class RateLimitHeaderParser
{
    /**
     * Parses rate limit information from HTTP response headers.
     * Supports standard headers from OpenAI, Anthropic, Groq, Mistral, DeepSeek, etc.
     *
     * @param array<string, string|string[]|int|float|null> $headers
     * @return array{
     *     remainingRequests: ?int,
     *     limitRequests: ?int,
     *     remainingTokens: ?int,
     *     limitTokens: ?int,
     *     resetSeconds: ?int,
     *     retryAfterSeconds: ?int,
     *     quotaRatio: ?float,
     *     isExhausted: bool
     * }
     */
    public static function parse(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $key = strtolower(trim((string) $name));
            if (is_array($value)) {
                $normalized[$key] = trim((string) reset($value));
            } elseif ($value !== null) {
                $normalized[$key] = trim((string) $value);
            }
        }

        $remainingRequests = self::findInteger($normalized, [
            'x-ratelimit-remaining-requests',
            'anthropic-ratelimit-requests-remaining',
            'ratelimit-remaining-requests',
            'ratelimit-remaining',
        ]);

        $limitRequests = self::findInteger($normalized, [
            'x-ratelimit-limit-requests',
            'anthropic-ratelimit-requests-limit',
            'ratelimit-limit-requests',
            'ratelimit-limit',
        ]);

        $remainingTokens = self::findInteger($normalized, [
            'x-ratelimit-remaining-tokens',
            'anthropic-ratelimit-tokens-remaining',
            'ratelimit-remaining-tokens',
        ]);

        $limitTokens = self::findInteger($normalized, [
            'x-ratelimit-limit-tokens',
            'anthropic-ratelimit-tokens-limit',
            'ratelimit-limit-tokens',
        ]);

        $resetRaw = self::findString($normalized, [
            'x-ratelimit-reset-requests',
            'x-ratelimit-reset-tokens',
            'anthropic-ratelimit-requests-reset',
            'anthropic-ratelimit-tokens-reset',
            'ratelimit-reset',
            'x-ratelimit-reset',
        ]);

        $resetSeconds = self::parseResetDuration($resetRaw);

        $retryAfterRaw = self::findString($normalized, ['retry-after']);
        $retryAfterSeconds = RetryAfterParser::parse($retryAfterRaw);

        $quotaRatio = self::calculateRatio($remainingRequests, $limitRequests, $remainingTokens, $limitTokens);

        $isExhausted = ($remainingRequests !== null && $remainingRequests <= 0)
            || ($remainingTokens !== null && $remainingTokens <= 0)
            || ($quotaRatio !== null && $quotaRatio <= 0.0);

        return [
            'remainingRequests' => $remainingRequests,
            'limitRequests' => $limitRequests,
            'remainingTokens' => $remainingTokens,
            'limitTokens' => $limitTokens,
            'resetSeconds' => $resetSeconds ?? $retryAfterSeconds,
            'retryAfterSeconds' => $retryAfterSeconds,
            'quotaRatio' => $quotaRatio,
            'isExhausted' => $isExhausted,
        ];
    }

    /**
     * @param array<string, string> $headers
     * @param string[] $candidates
     */
    private static function findInteger(array $headers, array $candidates): ?int
    {
        foreach ($candidates as $candidate) {
            if (isset($headers[$candidate]) && $headers[$candidate] !== '') {
                $val = $headers[$candidate];
                if (ctype_digit($val) || (str_starts_with($val, '-') && ctype_digit(substr($val, 1)))) {
                    return (int) $val;
                }
                if (is_numeric($val)) {
                    return (int) round((float) $val);
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $headers
     * @param string[] $candidates
     */
    private static function findString(array $headers, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (isset($headers[$candidate]) && $headers[$candidate] !== '') {
                return $headers[$candidate];
            }
        }

        return null;
    }

    /**
     * Parses duration string (e.g. "6s", "2m30s", "500ms"), unix timestamp or HTTP date.
     */
    public static function parseResetDuration(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            $num = (int) $value;
            // If it's a future Unix timestamp (greater than year 2020: > 1_577_836_800)
            if ($num > 1_577_836_800) {
                return max(0, $num - time());
            }
            return max(0, $num);
        }

        // Check for compound duration like "1m20s" or "500ms" or "6s"
        if (preg_match('/^(?:(\d+)h)?(?:(\d+)m(?:in)?)?(?:(\d+(?:\.\d+)?)s)?(?:(\d+(?:\.\d+)?)ms)?$/i', $value, $matches)) {
            $hours = !empty($matches[1]) ? (int) $matches[1] : 0;
            $mins = !empty($matches[2]) ? (int) $matches[2] : 0;
            $secs = !empty($matches[3]) ? (float) $matches[3] : 0.0;
            $ms = !empty($matches[4]) ? (float) $matches[4] : 0.0;

            $totalSeconds = ($hours * 3600) + ($mins * 60) + $secs + ($ms / 1000.0);
            if ($totalSeconds > 0) {
                return (int) max(1, ceil($totalSeconds));
            }
        }

        // Try date parsing
        try {
            $date = new DateTimeImmutable($value);
            $now = new DateTimeImmutable();
            $diff = $date->getTimestamp() - $now->getTimestamp();
            return max(0, $diff);
        } catch (Throwable) {
            return null;
        }
    }

    private static function calculateRatio(
        ?int $remainingRequests,
        ?int $limitRequests,
        ?int $remainingTokens,
        ?int $limitTokens,
    ): ?float {
        $ratios = [];

        if ($remainingRequests !== null && $limitRequests !== null && $limitRequests > 0) {
            $ratios[] = min(max((float) $remainingRequests / (float) $limitRequests, 0.0), 1.0);
        }

        if ($remainingTokens !== null && $limitTokens !== null && $limitTokens > 0) {
            $ratios[] = min(max((float) $remainingTokens / (float) $limitTokens, 0.0), 1.0);
        }

        if ($ratios !== []) {
            return min($ratios);
        }

        if (($remainingRequests !== null && $remainingRequests <= 0) || ($remainingTokens !== null && $remainingTokens <= 0)) {
            return 0.0;
        }

        return null;
    }
}
