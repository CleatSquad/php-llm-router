<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\RateLimit;

use CleatSquad\LlmRouter\RateLimit\AtomicRateLimitStoreInterface;
use CleatSquad\LlmRouter\RateLimit\RedisRateLimitStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Redis;
use RuntimeException;

/**
 * Invariant protected: a quota shared across workers never under-counts.
 * Bug covered: getWindow()/saveWindow() is a read-modify-write, so two workers
 *   reading "3 used" before either writes both store 4, losing one request and
 *   admitting more traffic than the ceiling allows.
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
final class RedisRateLimitStoreAtomicityTest extends TestCase
{
    private function store(Redis $redis): RedisRateLimitStore
    {
        return new RedisRateLimitStore($redis);
    }

    public function testExposesTheAtomicContract(): void
    {
        $this->assertInstanceOf(AtomicRateLimitStoreInterface::class, $this->store(new Redis()));
    }

    public function testAnAbsentCounterReadsAsNoWindowYet(): void
    {
        $this->assertNull($this->store(new Redis())->getWindow('never-used'));
    }

    public function testTwoWorkersSharingOneBackendNeverLoseAnIncrement(): void
    {
        $redis = new Redis();
        $workerA = $this->store($redis);
        $workerB = $this->store($redis);

        // Interleaved exactly the way a lost update happens: each worker
        // reserves without waiting for the other to finish its own cycle.
        for ($i = 0; $i < 10; $i++) {
            $this->assertNotNull($workerA->tryAcquire('groq', 60, 100, null));
            $this->assertNotNull($workerB->tryAcquire('groq', 60, 100, null));
        }

        $this->assertSame(20, $workerA->getWindow('groq')?->requestCount);
    }

    public function testTheLimitIsReachedExactlyAndTheNextRequestIsRefused(): void
    {
        $store = $this->store(new Redis());

        for ($i = 1; $i <= 5; $i++) {
            $window = $store->tryAcquire('groq', 60, 5, null);
            $this->assertNotNull($window, "request $i should fit under a limit of 5");
            $this->assertSame($i, $window->requestCount);
        }

        $this->assertNull($store->tryAcquire('groq', 60, 5, null), 'the 6th request is one over the limit');
    }

    public function testARefusedReservationDoesNotConsumeQuotaPermanently(): void
    {
        $redis = new Redis();
        $store = $this->store($redis);

        $store->tryAcquire('groq', 60, 1, null);
        $store->tryAcquire('groq', 60, 1, null); // refused
        $store->tryAcquire('groq', 60, 1, null); // refused

        // Had the refusals kept their increment, the counter would read 3 and
        // the window would stay poisoned for its whole duration.
        $this->assertSame(1, $store->getWindow('groq')?->requestCount);
    }

    public function testTokenCeilingIsEnforcedFromAccumulatedUsage(): void
    {
        $store = $this->store(new Redis());

        $this->assertNotNull($store->tryAcquire('groq', 60, null, 1_000));
        $store->addTokens('groq', 60, 999);
        $this->assertNotNull($store->tryAcquire('groq', 60, null, 1_000), 'still just under the ceiling');

        $store->addTokens('groq', 60, 1);
        $this->assertNull($store->tryAcquire('groq', 60, null, 1_000), 'ceiling reached');
    }

    public function testConcurrentTokenAccountingSumsInsteadOfOverwriting(): void
    {
        $redis = new Redis();
        $workerA = $this->store($redis);
        $workerB = $this->store($redis);

        $workerA->addTokens('groq', 60, 300);
        $workerB->addTokens('groq', 60, 700);

        $this->assertSame(1_000, $workerA->getWindow('groq')?->tokenCount);
    }

    public function testCountersAreScopedPerDriver(): void
    {
        $store = $this->store(new Redis());

        $store->tryAcquire('groq', 60, 10, null);
        $store->tryAcquire('openai', 60, 10, null);
        $store->tryAcquire('openai', 60, 10, null);

        $this->assertSame(1, $store->getWindow('groq')?->requestCount);
        $this->assertSame(2, $store->getWindow('openai')?->requestCount);
    }

    public function testANewWindowStartsWithAFreshCounterInsteadOfInheritingTheOldOne(): void
    {
        $redis = new Redis();
        $store = $this->store($redis);

        // A 1-second window rolls over on its own: the counter key is derived
        // from the window index, so the next window simply addresses a new key.
        $store->tryAcquire('groq', 1, 5, null);
        $before = $store->tryAcquire('groq', 1, 5, null);
        $this->assertNotNull($before);

        $firstIndex = intdiv(time(), 1);
        while (intdiv(time(), 1) === $firstIndex) {
            usleep(50_000);
        }

        $after = $store->tryAcquire('groq', 1, 5, null);
        $this->assertNotNull($after);
        $this->assertSame(1, $after->requestCount, 'the new window starts from zero');
    }

    public function testAnUnavailableBackendFailsClosedRatherThanAdmittingTraffic(): void
    {
        $store = $this->store(new class extends Redis {
            public function hIncrBy(string $key, string $field, int $value): int
            {
                throw new RuntimeException('Connection refused');
            }
        });

        // Deliberately not fail-open: a quota is a protection mechanism, and
        // an unreachable coordination backend must not silently mean "no limit".
        $this->expectException(RuntimeException::class);
        $store->tryAcquire('groq', 60, 5, null);
    }
}
