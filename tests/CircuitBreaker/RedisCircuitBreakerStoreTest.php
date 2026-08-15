<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\CircuitBreaker;

use CleatSquad\LlmRouter\CircuitBreaker\CircuitBreakerState;
use CleatSquad\LlmRouter\CircuitBreaker\RedisCircuitBreakerStore;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Redis;

final class RedisCircuitBreakerStoreTest extends TestCase
{
    public function testUnknownDriverStartsClosedWithNoFailures(): void
    {
        $store = new RedisCircuitBreakerStore(new Redis());

        $state = $store->getState('unknown');

        $this->assertSame(0, $state->failureCount);
        $this->assertFalse($state->isOpen(new DateTimeImmutable()));
    }

    public function testRoundTripsAnOpenState(): void
    {
        $store = new RedisCircuitBreakerStore(new Redis());
        $openUntil = (new DateTimeImmutable())->modify('+60 seconds');
        $state = new CircuitBreakerState(failureCount: 5, openUntil: $openUntil);

        $store->saveState('claude', $state);
        $reloaded = $store->getState('claude');

        $this->assertSame(5, $reloaded->failureCount);
        $this->assertTrue($reloaded->isOpen(new DateTimeImmutable()));
    }

    public function testDifferentDriverIdsDoNotCollide(): void
    {
        $store = new RedisCircuitBreakerStore(new Redis());

        $store->saveState('claude', new CircuitBreakerState(failureCount: 3));
        $store->saveState('ollama', new CircuitBreakerState(failureCount: 1));

        $this->assertSame(3, $store->getState('claude')->failureCount);
        $this->assertSame(1, $store->getState('ollama')->failureCount);
    }

    public function testTwoStoresShareStateThroughTheSameRedisConnection(): void
    {
        $redis = new Redis();
        $first = new RedisCircuitBreakerStore($redis);
        $second = new RedisCircuitBreakerStore($redis);

        $first->saveState('claude', new CircuitBreakerState(failureCount: 2));

        $this->assertSame(2, $second->getState('claude')->failureCount);
    }
}
