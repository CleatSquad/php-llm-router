<?php

declare(strict_types=1);

namespace LlmRouter\Cache;

use LlmRouter\DTO\LLMResponse;
use Redis;

/**
 * CacheStoreInterface backed by Redis, sharing cached responses across requests and workers.
 * Entries are PHP-serialized with a native Redis expiry (SETEX), so no separate cleanup pass is needed.
 */
final class RedisCacheStore implements CacheStoreInterface
{
    public function __construct(
        private readonly Redis $redis,
        private readonly string $prefix = 'llm_router:cache:',
    ) {}

    public function get(string $key): ?LLMResponse
    {
        $raw = $this->redis->get($this->prefix . $key);
        if ($raw === false) {
            return null;
        }

        $response = @unserialize($raw);
        return $response instanceof LLMResponse ? $response : null;
    }

    public function set(string $key, LLMResponse $response, int $ttlSeconds): void
    {
        $this->redis->setex($this->prefix . $key, max($ttlSeconds, 1), serialize($response));
    }
}
