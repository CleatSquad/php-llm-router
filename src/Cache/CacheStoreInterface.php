<?php

declare(strict_types=1);

namespace LlmRouter\Cache;

use LlmRouter\DTO\LLMResponse;

/**
 * Where CachingDriver persists cached chat() responses; implement against Redis/DB to share the cache across processes.
 */
interface CacheStoreInterface
{
    public function get(string $key): ?LLMResponse;

    public function set(string $key, LLMResponse $response, int $ttlSeconds): void;
}
