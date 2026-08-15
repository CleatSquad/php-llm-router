<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Cache;

use CleatSquad\LlmRouter\DTO\LLMResponse;
use DateTimeImmutable;

/**
 * Default CacheStoreInterface: a same-process array, fine for a CLI session or single worker.
 * Use RedisCacheStore instead when the cache needs to be shared across requests or processes.
 */
final class InMemoryCacheStore implements CacheStoreInterface
{
    /** @var array<string, array{response: LLMResponse, expiresAt: DateTimeImmutable}> */
    private array $entries = [];

    public function get(string $key): ?LLMResponse
    {
        $entry = $this->entries[$key] ?? null;
        if ($entry === null) {
            return null;
        }

        if ($entry['expiresAt'] <= new DateTimeImmutable()) {
            unset($this->entries[$key]);
            return null;
        }

        return $entry['response'];
    }

    public function set(string $key, LLMResponse $response, int $ttlSeconds): void
    {
        $this->entries[$key] = [
            'response' => $response,
            'expiresAt' => (new DateTimeImmutable())->modify(sprintf('%+d seconds', $ttlSeconds)),
        ];
    }
}
