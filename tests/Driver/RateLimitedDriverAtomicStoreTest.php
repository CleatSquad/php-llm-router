<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Driver;

use CleatSquad\LlmRouter\Driver\RateLimitedDriver;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\RateLimit\InMemoryRateLimitStore;
use CleatSquad\LlmRouter\RateLimit\RedisRateLimitStore;
use CleatSquad\LlmRouter\Tests\Fixtures\ControllableDriver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Redis;
use RuntimeException;

/**
 * Invariant protected: RateLimitedDriver enforces the same ceiling whichever
 *   store it is given, and takes the atomic path whenever the store offers one.
 * Bug covered: with a shared store, check-then-count let two workers both pass
 *   the last slot; the driver now reserves through tryAcquire() instead.
 * Type: regression + concurrency.
 */
#[Group('security')]
/**
 * These doubles extend \Redis, so the class has to exist for the file to even
 * load. Without the extension the suite reported errors that read as failures
 * of the code under test; declared as a requirement, it reports a skip, which
 * is what an absent extension actually is.
 */
#[RequiresPhpExtension('redis')]
final class RateLimitedDriverAtomicStoreTest extends TestCase
{
    private function request(): LLMRequest
    {
        return new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]);
    }

    public function testTheCeilingHoldsAcrossTwoDriversSharingOneAtomicStore(): void
    {
        $redis = new Redis();
        $workerA = new RateLimitedDriver(
            new ControllableDriver('groq'),
            new RedisRateLimitStore($redis),
            maxRequestsPerMinute: 4,
            maxWaitSeconds: 0.0,
        );
        $workerB = new RateLimitedDriver(
            new ControllableDriver('groq'),
            new RedisRateLimitStore($redis),
            maxRequestsPerMinute: 4,
            maxWaitSeconds: 0.0,
        );

        $workerA->chat($this->request());
        $workerB->chat($this->request());
        $workerA->chat($this->request());
        $workerB->chat($this->request());

        // Four went through; the fifth must be refused no matter which worker
        // asks. Under check-then-count both workers could still see room here.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Rate limit exceeded/');
        $workerA->chat($this->request());
    }

    public function testAcceptedRequestsAreCountedExactlyOncePerCall(): void
    {
        $store = new RedisRateLimitStore(new Redis());
        $driver = new RateLimitedDriver(
            new ControllableDriver('groq'),
            $store,
            maxRequestsPerMinute: 10,
            maxWaitSeconds: 0.0,
        );

        $driver->chat($this->request());
        $driver->chat($this->request());
        $driver->chat($this->request());

        // Reserving in tryAcquire() and adding tokens afterwards must not
        // double-count the request itself.
        $this->assertSame(3, $store->getWindow('groq')?->requestCount);
    }

    public function testTokenUsageIsAccumulatedOnTheAtomicPath(): void
    {
        $store = new RedisRateLimitStore(new Redis());
        $driver = new RateLimitedDriver(
            new ControllableDriver('groq', [], completionTokens: 50),
            $store,
            maxTokensPerMinute: 1_000,
            maxWaitSeconds: 0.0,
        );

        $driver->chat($this->request());
        $driver->chat($this->request());

        $this->assertGreaterThan(0, $store->getWindow('groq')?->tokenCount);
    }

    public function testARefusedCallDoesNotBurnQuotaForTheRestOfTheWindow(): void
    {
        $store = new RedisRateLimitStore(new Redis());
        $driver = new RateLimitedDriver(
            new ControllableDriver('groq'),
            $store,
            maxRequestsPerMinute: 1,
            maxWaitSeconds: 0.0,
        );

        $driver->chat($this->request());

        for ($i = 0; $i < 3; $i++) {
            try {
                $driver->chat($this->request());
            } catch (RuntimeException) {
                // expected
            }
        }

        $this->assertSame(1, $store->getWindow('groq')?->requestCount);
    }

    public function testTheProcessLocalStoreKeepsItsOriginalBehaviour(): void
    {
        // The legacy read-modify-write path is untouched for stores that don't
        // offer an atomic increment — they are process-local by construction,
        // where the race being fixed cannot happen.
        $inner = new ControllableDriver('groq');
        $driver = new RateLimitedDriver(
            $inner,
            new InMemoryRateLimitStore(),
            maxRequestsPerMinute: 2,
            maxWaitSeconds: 0.0,
        );

        $driver->chat($this->request());
        $driver->chat($this->request());

        $this->expectException(RuntimeException::class);
        $driver->chat($this->request());
    }

    public function testAnUnavailableSharedStoreFailsClosedRatherThanLiftingTheLimit(): void
    {
        $driver = new RateLimitedDriver(
            new ControllableDriver('groq'),
            new RedisRateLimitStore(new class extends Redis {
                public function hIncrBy(string $key, string $field, int $value): int
                {
                    throw new RuntimeException('Connection refused');
                }
            }),
            maxRequestsPerMinute: 5,
            maxWaitSeconds: 0.0,
        );

        // Unlike the cache and the breaker, the quota does not fail open: a
        // coordination outage must not silently become "unlimited traffic".
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection refused');
        $driver->chat($this->request());
    }
}
