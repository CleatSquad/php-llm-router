<?php

declare(strict_types=1);

namespace LlmRouter\Cache;

use LlmRouter\DTO\LLMResponse;
use Redis;

/**
 * CacheStoreInterface backed by Redis (ext-redis), so cached chat()
 * responses are shared across requests and worker processes instead of
 * living only in one process like InMemoryCacheStore. Entries are
 * PHP-serialized and stored with a native Redis expiry (SETEX) — no
 * separate cleanup pass needed, expired keys just disappear.
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
