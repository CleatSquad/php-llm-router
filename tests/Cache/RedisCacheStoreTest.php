<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Cache;

use CleatSquad\LlmRouter\Cache\RedisCacheStore;
use CleatSquad\LlmRouter\DTO\LLMResponse;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Redis;

/**
 * Exercises the phpredis-backed store, so the extension has to be there for
 * the test to mean anything. Declared rather than discovered: without it the
 * suite reported an error that read as a failure of the code under test.
 */
#[RequiresPhpExtension('redis')]
final class RedisCacheStoreTest extends TestCase
{
    private function response(string $content = 'hi'): LLMResponse
    {
        return new LLMResponse(
            content: $content,
            model: 'fake-model',
            promptTokens: 1,
            completionTokens: 2,
            totalTokens: 3,
            costUsd: 0.01,
            latencyMs: 10,
            toolCalls: null,
            finishReason: 'stop',
            metadata: ['k' => 'v'],
        );
    }

    public function testMissingKeyReturnsNull(): void
    {
        $store = new RedisCacheStore(new Redis());

        $this->assertNull($store->get('missing'));
    }

    public function testRoundTripsAStoredResponse(): void
    {
        $store = new RedisCacheStore(new Redis());
        $response = $this->response();

        $store->set('key', $response, 60);
        $cached = $store->get('key');

        $this->assertNotNull($cached);
        $this->assertSame($response->content, $cached->content);
        $this->assertSame($response->metadata, $cached->metadata);
    }

    public function testDifferentKeysDoNotCollide(): void
    {
        $store = new RedisCacheStore(new Redis());

        $store->set('a', $this->response('alpha'), 60);
        $store->set('b', $this->response('beta'), 60);

        $this->assertSame('alpha', $store->get('a')?->content);
        $this->assertSame('beta', $store->get('b')?->content);
    }

    public function testTwoStoresShareStateThroughTheSameRedisConnection(): void
    {
        $redis = new Redis();
        $first = new RedisCacheStore($redis);
        $second = new RedisCacheStore($redis);

        $first->set('key', $this->response('shared'), 60);

        $this->assertSame('shared', $second->get('key')?->content);
    }

    public function testDifferentPrefixesDoNotCollideOnTheSameRedisConnection(): void
    {
        $redis = new Redis();
        $first = new RedisCacheStore($redis, prefix: 'app-one:');
        $second = new RedisCacheStore($redis, prefix: 'app-two:');

        $first->set('key', $this->response('one'), 60);

        $this->assertNull($second->get('key'), 'a differently-prefixed store must not see the other one\'s entries');
    }
}
