<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\CircuitBreaker;

use CleatSquad\LlmRouter\CircuitBreaker\CircuitBreakerState;
use CleatSquad\LlmRouter\CircuitBreaker\RedisCircuitBreakerStore;
use CleatSquad\LlmRouter\Tests\Fixtures\GadgetProbe;
use CleatSquad\LlmRouter\Tests\Fixtures\RecordingLogger;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Redis;
use RuntimeException;

/**
 * Invariant protected: breaker state read back from a shared store never
 *   decides which PHP class gets instantiated, and losing the store never
 *   takes every provider down with it.
 * Bug covered: unserialize($raw) on a shared value (deserialization gadget),
 *   plus an uncaught Redis exception turning a cache blip into a total outage.
 * Type: security + resilience regression.
 */
#[Group('security')]
final class RedisCircuitBreakerStoreSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        GadgetProbe::reset();
    }

    private function poison(Redis $redis, string $driverId, string $payload): void
    {
        $redis->setex('llm_router:circuit_breaker:' . $driverId, 60, $payload);
        GadgetProbe::reset();
    }

    public function testAForbiddenClassPayloadIsNeverInstantiated(): void
    {
        $redis = new Redis();
        $store = new RedisCircuitBreakerStore($redis);

        $this->poison($redis, 'groq', serialize(new GadgetProbe()));

        $state = $store->getState('groq');

        $this->assertFalse(GadgetProbe::$instantiated, 'decoding must not construct the gadget class');
        $this->assertSame(0, $state->failureCount, 'an unusable value reads as a fresh, closed breaker');
    }

    public function testACorruptOrTruncatedPayloadReadsAsAClosedBreaker(): void
    {
        $redis = new Redis();
        $store = new RedisCircuitBreakerStore($redis);

        $this->poison($redis, 'a', '{"failureCount":');
        $this->poison($redis, 'b', 'garbage');
        $this->poison($redis, 'c', '"just a string"');
        $this->poison($redis, 'd', '{"failureCount":"not-an-int"}');

        foreach (['a', 'b', 'c', 'd'] as $driverId) {
            $state = $store->getState($driverId);
            $this->assertSame(0, $state->failureCount);
            $this->assertFalse($state->isOpen(new DateTimeImmutable()));
        }
    }

    public function testAValidOpenStateStillRoundTrips(): void
    {
        $store = new RedisCircuitBreakerStore(new Redis());
        $openUntil = (new DateTimeImmutable())->modify('+120 seconds');

        $store->saveState('groq', new CircuitBreakerState(7, $openUntil));
        $reloaded = $store->getState('groq');

        $this->assertSame(7, $reloaded->failureCount);
        $this->assertSame($openUntil->getTimestamp(), $reloaded->openUntil?->getTimestamp());
        $this->assertTrue($reloaded->isOpen(new DateTimeImmutable()));
    }

    public function testAnUnavailableStoreFailsOpenSoCallsAreStillAttempted(): void
    {
        $logger = new RecordingLogger();
        $store = new RedisCircuitBreakerStore(new class extends Redis {
            public function get(string $key): string|false
            {
                throw new RuntimeException('Connection refused');
            }
        }, logger: $logger);

        $state = $store->getState('groq');

        // Fail-open on purpose: the breaker spares callers a doomed call, it
        // does not authorise them. Failing closed here would turn one Redis
        // outage into an outage of every configured provider at once.
        $this->assertFalse($state->isOpen(new DateTimeImmutable()));
        $this->assertNotEmpty($logger->records, 'fail-open must not mean failing silently');
    }

    public function testAFailedWriteIsLoggedAndDroppedRatherThanPropagated(): void
    {
        $logger = new RecordingLogger();
        $store = new RedisCircuitBreakerStore(new class extends Redis {
            public function setex(string $key, int $ttl, string $value): bool
            {
                throw new RuntimeException('Connection refused');
            }
        }, logger: $logger);

        $store->saveState('groq', new CircuitBreakerState(3, null));

        $this->assertNotEmpty($logger->records);
    }

    public function testAStoreComingBackAfterAnOutageIsUsedAgain(): void
    {
        $redis = new class extends Redis {
            public bool $down = true;

            public function get(string $key): string|false
            {
                if ($this->down) {
                    throw new RuntimeException('Connection refused');
                }

                return parent::get($key);
            }

            public function setex(string $key, int $ttl, string $value): bool
            {
                if ($this->down) {
                    throw new RuntimeException('Connection refused');
                }

                return parent::setex($key, $ttl, $value);
            }
        };
        $store = new RedisCircuitBreakerStore($redis);

        $store->saveState('groq', new CircuitBreakerState(5, (new DateTimeImmutable())->modify('+60 seconds')));
        $this->assertSame(0, $store->getState('groq')->failureCount);

        $redis->down = false;

        $store->saveState('groq', new CircuitBreakerState(5, (new DateTimeImmutable())->modify('+60 seconds')));
        $this->assertSame(5, $store->getState('groq')->failureCount);
        $this->assertTrue($store->getState('groq')->isOpen(new DateTimeImmutable()));
    }
}
