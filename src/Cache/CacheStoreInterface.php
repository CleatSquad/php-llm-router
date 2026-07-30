<?php

declare(strict_types=1);

namespace LlmRouter\Cache;

use LlmRouter\DTO\LLMResponse;

/**
 * Where CachingDriver persists cached chat() responses. The default is
 * a same-process array (InMemoryCacheStore); implement this against
 * Redis/DB/etc. to share the cache across requests or worker processes.
 */
interface CacheStoreInterface
{
    public function get(string $key): ?LLMResponse;

    public function set(string $key, LLMResponse $response, int $ttlSeconds): void;
}
