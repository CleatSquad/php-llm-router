<?php

declare(strict_types=1);

namespace LlmRouter\Serialization;

use JsonException;
use LlmRouter\DTO\LLMResponse;

/**
 * Turns an LLMResponse into JSON and back for the shared cache stores.
 *
 * Deliberately not serialize()/unserialize(): a cache entry comes back from a
 * shared backend that other processes — and, if it is ever misconfigured or
 * compromised, other people — can write to. PHP deserialization turns such a
 * value into object instantiation, running __wakeup()/__destruct() on whatever
 * class the payload names, which is a remote-code-execution primitive given a
 * suitable gadget chain anywhere in the autoloader. Checking the resulting
 * type afterwards does not help: the damage is done while the object graph is
 * being built, before any check can run.
 *
 * decode() therefore only ever reads scalars out of a JSON document and passes
 * them to a constructor this package controls. No class name in the payload,
 * no instantiation driven by the payload.
 */
final class LLMResponseCodec
{
    /**
     * @throws JsonException
     */
    public static function encode(LLMResponse $response): string
    {
        return json_encode([
            'content' => $response->content,
            'model' => $response->model,
            'promptTokens' => $response->promptTokens,
            'completionTokens' => $response->completionTokens,
            'totalTokens' => $response->totalTokens,
            'costUsd' => $response->costUsd,
            'latencyMs' => $response->latencyMs,
            'toolCalls' => $response->toolCalls,
            'finishReason' => $response->finishReason,
            'metadata' => $response->metadata,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Rebuilds the DTO explicitly from scalars. Anything that isn't the shape
     * written by encode() — truncated, malformed, or tampered with — is
     * rejected as null rather than coerced into a half-populated response.
     *
     * @throws JsonException on a payload that isn't valid JSON at all.
     */
    public static function decode(string $raw): ?LLMResponse
    {
        /** @var mixed $data */
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data)
            || !is_string($data['content'] ?? null)
            || !is_string($data['model'] ?? null)
            || !is_string($data['finishReason'] ?? null)
        ) {
            return null;
        }

        $toolCalls = $data['toolCalls'] ?? null;
        $metadata = $data['metadata'] ?? [];

        return new LLMResponse(
            content: $data['content'],
            model: $data['model'],
            promptTokens: (int) ($data['promptTokens'] ?? 0),
            completionTokens: (int) ($data['completionTokens'] ?? 0),
            totalTokens: (int) ($data['totalTokens'] ?? 0),
            costUsd: (float) ($data['costUsd'] ?? 0.0),
            latencyMs: (int) ($data['latencyMs'] ?? 0),
            toolCalls: is_array($toolCalls) ? $toolCalls : null,
            finishReason: $data['finishReason'],
            metadata: is_array($metadata) ? $metadata : [],
        );
    }
}
