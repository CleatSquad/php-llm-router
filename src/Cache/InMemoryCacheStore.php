<?php

declare(strict_types=1);

namespace LlmRouter\Cache;

use LlmRouter\DTO\LLMResponse;
use DateTimeImmutable;

/**
 * Default CacheStoreInterface: plain array, lives only for the current
 * process. Fine for a CLI session or a single long-lived worker; a
 * multi-process web app wanting a cache shared across requests should
 * implement the interface against Redis/DB instead.
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
