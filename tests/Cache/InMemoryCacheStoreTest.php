<?php

declare(strict_types=1);

namespace LlmRouter\Tests\Cache;

use LlmRouter\Cache\InMemoryCacheStore;
use LlmRouter\DTO\LLMResponse;
use PHPUnit\Framework\TestCase;

final class InMemoryCacheStoreTest extends TestCase
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
        $store = new InMemoryCacheStore();

        $this->assertNull($store->get('missing'));
    }

    public function testRoundTripsAStoredResponse(): void
    {
        $store = new InMemoryCacheStore();
        $response = $this->response();

        $store->set('key', $response, 60);
        $cached = $store->get('key');

        $this->assertNotNull($cached);
        $this->assertSame($response, $cached, 'InMemoryCacheStore keeps the exact same object, no (de)serialization round trip.');
        $this->assertSame('hi', $cached->content);
        $this->assertSame(['k' => 'v'], $cached->metadata);
    }

    public function testDifferentKeysDoNotCollide(): void
    {
        $store = new InMemoryCacheStore();

        $store->set('a', $this->response('alpha'), 60);
        $store->set('b', $this->response('beta'), 60);

        $this->assertSame('alpha', $store->get('a')?->content);
        $this->assertSame('beta', $store->get('b')?->content);
    }

    public function testSettingTheSameKeyAgainOverwritesThePreviousEntry(): void
    {
        $store = new InMemoryCacheStore();

        $store->set('key', $this->response('first'), 60);
        $store->set('key', $this->response('second'), 60);

        $this->assertSame('second', $store->get('key')?->content);
    }

    /**
     * A negative TTL puts expiresAt in the past immediately, so get() must
     * treat the entry as already expired without needing to sleep.
     */
    public function testEntryIsExpiredOnceTtlHasElapsed(): void
    {
        $store = new InMemoryCacheStore();

        $store->set('key', $this->response(), -1);

        $this->assertNull($store->get('key'));
    }

    public function testEntryWithZeroTtlIsAlreadyExpired(): void
    {
        $store = new InMemoryCacheStore();

        $store->set('key', $this->response(), 0);

        $this->assertNull($store->get('key'));
    }

    public function testEntryWithAPositiveTtlIsStillReadableImmediately(): void
    {
        $store = new InMemoryCacheStore();

        $store->set('key', $this->response(), 60);

        $this->assertNotNull($store->get('key'));
    }

    public function testAnExpiredEntryIsRemovedSoALaterSetCanReplaceItCleanly(): void
    {
        $store = new InMemoryCacheStore();

        $store->set('key', $this->response('stale'), -1);
        $this->assertNull($store->get('key'), 'first read should observe the expiry and evict the entry');

        $store->set('key', $this->response('fresh'), 60);

        $this->assertSame('fresh', $store->get('key')?->content);
    }
}
