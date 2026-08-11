<?php

declare(strict_types=1);

namespace LlmRouter\Serialization;

use DateTimeImmutable;
use JsonException;
use LlmRouter\CircuitBreaker\CircuitBreakerState;

/**
 * JSON codec for breaker state held in a shared store — a failure count and a
 * Unix timestamp, nothing more.
 *
 * Same rule as LLMResponseCodec: never unserialize() a value read back from a
 * shared backend, because that hands control of which class gets instantiated
 * to whoever can write to it. See LLMResponseCodec for the full rationale.
 */
final class CircuitBreakerStateCodec
{
    /**
     * @throws JsonException
     */
    public static function encode(CircuitBreakerState $state): string
    {
        return json_encode([
            'failureCount' => $state->failureCount,
            'openUntil' => $state->openUntil?->getTimestamp(),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @throws JsonException on a payload that isn't valid JSON at all.
     */
    public static function decode(string $raw): ?CircuitBreakerState
    {
        /** @var mixed $data */
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data) || !is_int($data['failureCount'] ?? null)) {
            return null;
        }

        $openUntil = $data['openUntil'] ?? null;

        return new CircuitBreakerState(
            $data['failureCount'],
            is_int($openUntil) ? (new DateTimeImmutable())->setTimestamp($openUntil) : null,
        );
    }
}
