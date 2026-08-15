<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Driver;

use CleatSquad\LlmRouter\Driver\CachingDriver;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Tests\Fixtures\ControllableDriver;
use PHPUnit\Framework\TestCase;

final class CachingDriverTest extends TestCase
{
    public function testCachesIdenticalRequestsWithinTtl(): void
    {
        $inner = new ControllableDriver('fake');
        $driver = new CachingDriver($inner, ttlSeconds: 60);
        $request = new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]);

        $first = $driver->chat($request);
        $second = $driver->chat($request);

        $this->assertSame($first->content, $second->content);
        $this->assertSame(1, $inner->callCount, 'the second identical call must be served from cache');
    }

    public function testDoesNotCacheAcrossDifferentRequests(): void
    {
        $inner = new ControllableDriver('fake');
        $driver = new CachingDriver($inner, ttlSeconds: 60);

        $driver->chat(new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]));
        $driver->chat(new LLMRequest(messages: [['role' => 'user', 'content' => 'bye']]));

        $this->assertSame(2, $inner->callCount, 'different messages must not share a cache entry');
    }

    public function testDoesNotCacheAcrossDifferentModels(): void
    {
        $inner = new ControllableDriver('fake');
        $driver = new CachingDriver($inner, ttlSeconds: 60);
        $messages = [['role' => 'user', 'content' => 'hi']];

        $driver->chat(new LLMRequest(messages: $messages, model: 'model-a'));
        $driver->chat(new LLMRequest(messages: $messages, model: 'model-b'));

        $this->assertSame(2, $inner->callCount, 'different models must not share a cache entry');
    }

    public function testEntryExpiresAfterTtl(): void
    {
        $inner = new ControllableDriver('fake');
        $driver = new CachingDriver($inner, ttlSeconds: -1);
        $request = new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]);

        $driver->chat($request);
        $driver->chat($request);

        $this->assertSame(2, $inner->callCount, 'a negative TTL means the entry is already expired on the next call');
    }

    public function testStreamAlwaysBypassesTheCache(): void
    {
        $inner = new ControllableDriver('fake');
        $driver = new CachingDriver($inner, ttlSeconds: 60);
        $request = new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]);

        iterator_to_array($driver->stream($request));
        iterator_to_array($driver->stream($request));

        $this->assertSame(2, $inner->callCount, 'stream() must never be served from cache');
    }
}
