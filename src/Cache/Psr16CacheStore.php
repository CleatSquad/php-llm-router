<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Cache;

use CleatSquad\LlmRouter\DTO\LLMResponse;
use CleatSquad\LlmRouter\Serialization\LLMResponseCodec;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * CacheStoreInterface on top of any PSR-16 cache, for deployments without Redis:
 * APCu, filesystem, Memcached, PDO, ... via Symfony Cache, Laravel's store, or
 * any other PSR-16 implementation.
 *
 * Pick the backend with the sharing you actually need. APCu and filesystem are
 * shared across the PHP-FPM workers of one machine but not across machines;
 * Memcached and a database are shared across machines; an array adapter is
 * process-local and no better than InMemoryCacheStore.
 *
 * Encoding: JSON via LLMResponseCodec — the store hands the backend a string
 * and never lets a value read back from it drive object instantiation. This
 * matters more here than it looks: several PSR-16 adapters serialize whatever
 * value you give them, so storing the DTO directly would reintroduce exactly
 * the deserialization exposure the Redis store was fixed for.
 *
 * Failure semantics: fail-open, identical to RedisCacheStore. A backend outage
 * or an unreadable entry degrades to "no cache" and the LLM call proceeds;
 * every degradation is logged at warning level when a logger is supplied.
 */
final class Psr16CacheStore implements CacheStoreInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix = 'llm_router.cache.',
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function get(string $key): ?LLMResponse
    {
        try {
            /** @var mixed $raw */
            $raw = $this->cache->get($this->key($key));
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
            $this->logger?->warning('llm_router.cache.corrupt_entry', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function set(string $key, LLMResponse $response, int $ttlSeconds): void
    {
        try {
            $this->cache->set($this->key($key), LLMResponseCodec::encode($response), max($ttlSeconds, 1));
        } catch (Throwable $e) {
            $this->logger?->warning('llm_router.cache.store_unavailable', [
                'operation' => 'set',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * PSR-16 forbids {}()/\@: in keys, and a cache key here is a SHA-256 hex
     * digest, so only the caller-supplied prefix could ever introduce one.
     */
    private function key(string $key): string
    {
        return str_replace(['{', '}', '(', ')', '/', '\\', '@', ':'], '_', $this->prefix . $key);
    }
}
