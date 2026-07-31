<?php

declare(strict_types=1);

namespace LlmRouter\Tests\RateLimit;

use DateTimeImmutable;
use LlmRouter\RateLimit\RateLimitWindow;
use LlmRouter\RateLimit\RedisRateLimitStore;
use PHPUnit\Framework\TestCase;
use Redis;

final class RedisRateLimitStoreTest extends TestCase
{
    public function testUnknownDriverHasNoWindowYet(): void
    {
        $store = new RedisRateLimitStore(new Redis());

        $this->assertNull($store->getWindow('unknown'));
    }

    public function testRoundTripsAWindow(): void
    {
        $store = new RedisRateLimitStore(new Redis());
        $window = new RateLimitWindow(new DateTimeImmutable(), requestCount: 7, tokenCount: 500);

        $store->saveWindow('groq', $window);
        $reloaded = $store->getWindow('groq');

        $this->assertNotNull($reloaded);
        $this->assertSame(7, $reloaded->requestCount);
        $this->assertSame(500, $reloaded->tokenCount);
    }

    public function testDifferentDriverIdsDoNotCollide(): void
    {
        $store = new RedisRateLimitStore(new Redis());

        $store->saveWindow('groq', new RateLimitWindow(new DateTimeImmutable(), requestCount: 1));
        $store->saveWindow('openai', new RateLimitWindow(new DateTimeImmutable(), requestCount: 9));

        $this->assertSame(1, $store->getWindow('groq')?->requestCount);
        $this->assertSame(9, $store->getWindow('openai')?->requestCount);
    }

    public function testTwoStoresShareAQuotaThroughTheSameRedisConnection(): void
    {
        $redis = new Redis();
        $first = new RedisRateLimitStore($redis);
        $second = new RedisRateLimitStore($redis);

        $first->saveWindow('groq', new RateLimitWindow(new DateTimeImmutable(), requestCount: 3));

        $this->assertSame(3, $second->getWindow('groq')?->requestCount);
    }
}
