<?php

declare(strict_types=1);

namespace LlmRouter\Cache;

use LlmRouter\DTO\LLMResponse;
use LlmRouter\Serialization\LLMResponseCodec;
use Psr\Log\LoggerInterface;
use Redis;
use Throwable;

/**
 * CacheStoreInterface backed by Redis, sharing cached responses across requests and workers.
 * Entries carry a native Redis expiry (SETEX), so no separate cleanup pass is needed.
 *
 * Encoding: JSON, decoded into a flat array and passed field by field to the LLMResponse
 * constructor. The store never calls unserialize(), so a poisoned or corrupted cache entry
 * cannot make PHP instantiate an arbitrary class — the worst a hostile value can do is fail
 * validation and read as a cache miss.
 *
 * Failure semantics: fail-open. A Redis outage, a timeout, or an unreadable entry degrades to
 * "no cache" (get() misses, set() is dropped) and the LLM call proceeds, because a cache is an
 * optimisation and answering slowly beats not answering. Every such degradation is logged at
 * warning level when a logger is supplied, so fail-open never means failing silently.
 */
final class RedisCacheStore implements CacheStoreInterface
{
    public function __construct(
        private readonly Redis $redis,
        private readonly string $prefix = 'llm_router:cache:',
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function get(string $key): ?LLMResponse
    {
        try {
            $raw = $this->redis->get($this->prefix . $key);
        } catch (Throwable $e) {
            $this->logger?->warning('llm_router.cache.store_unavailable', [
                'operation' => 'get',
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (!is_string($raw)) {
            return null;
        }

        try {
            return LLMResponseCodec::decode($raw);
        } catch (Throwable $e) {
            $this->logger?->warning('llm_router.cache.corrupt_entry', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function set(string $key, LLMResponse $response, int $ttlSeconds): void
    {
        try {
            $this->redis->setex(
                $this->prefix . $key,
                max($ttlSeconds, 1),
                LLMResponseCodec::encode($response)
            );
        } catch (Throwable $e) {
            $this->logger?->warning('llm_router.cache.store_unavailable', [
                'operation' => 'set',
                'error' => $e->getMessage(),
            ]);
        }
    }

}
