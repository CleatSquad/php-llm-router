<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Driver;

use CleatSquad\LlmRouter\Driver\RetryingDriver;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Exception\RateLimitException;
use CleatSquad\LlmRouter\Tests\Fixtures\ControllableDriver;
use CleatSquad\LlmRouter\Tests\Fixtures\PartialStreamDriver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Invariant protected: when the provider says how long to wait, that delay
 *   replaces the computed backoff — and no retry ever happens once a fragment
 *   has reached the caller.
 * Bug covered: RetryingDriver computed an exponential backoff and ignored
 *   RateLimitException::getRetryAfterSeconds() entirely, so a 429 with
 *   "Retry-After: 30" was retried after ~0.5s and earned another 429.
 * Type: regression.
 */
final class RetryingDriverRetryAfterTest extends TestCase
{
    private function request(): LLMRequest
    {
        return new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]);
    }

    /**
     * Builds a driver whose waiting is captured instead of performed, so the
     * assertions are about the schedule it decided rather than about
     * wall-clock time — a 30s Retry-After has to be assertable in 30ms.
     *
     * @param array<int, float> $slept
     */
    private function driver(
        object $inner,
        array &$slept,
        int $maxAttempts = 3,
        float $baseDelaySeconds = 0.5,
        float $maxDelaySeconds = 8.0,
    ): RetryingDriver {
        return new RetryingDriver(
            $inner,
            $maxAttempts,
            $baseDelaySeconds,
            $maxDelaySeconds,
            static function (float $seconds) use (&$slept): void {
                $slept[] = $seconds;
            },
        );
    }

    public function testTheProviderSuppliedDelayReplacesTheComputedBackoff(): void
    {
        $slept = [];
        $inner = new ControllableDriver('fake', [new RateLimitException('rate limited', 30)]);
        $driver = $this->driver($inner, $slept, maxDelaySeconds: 60.0);

        $driver->chat($this->request());

        $this->assertSame([30.0], $slept, 'the 30s the provider asked for, not the ~0.5s backoff');
    }

    /**
     * The invariant this test has always protected — an absurd Retry-After must
     * not park the caller for an hour — now holds by giving up instead of by
     * capping. Capping still retried, and those attempts were certainties of
     * another 429 charged to the caller's time budget: the router that owns the
     * fallback chain never got its turn while there was still time to answer.
     */
    public function testACooldownBeyondTheRetryBudgetIsNotWaitedOutAtAll(): void
    {
        $slept = [];
        $inner = new ControllableDriver('fake', [new RateLimitException('rate limited', 3_600)]);
        $driver = $this->driver($inner, $slept, maxDelaySeconds: 8.0);

        try {
            $driver->chat($this->request());
            $this->fail('expected the rate limit to propagate without a doomed retry');
        } catch (RateLimitException $e) {
            $this->assertSame(3_600, $e->getRetryAfterSeconds(), 'the delay reaches the router intact');
        }

        $this->assertSame([], $slept, 'nobody is parked, not even for the capped 8s');
        $this->assertSame(1, $inner->callCount, 'one attempt, no retry that could only fail');
    }

    /** The boundary belongs to the budget: a delay that fits is still waited out. */
    public function testACooldownExactlyAtTheBudgetIsStillRetried(): void
    {
        $slept = [];
        $inner = new ControllableDriver('fake', [new RateLimitException('rate limited', 8)]);
        $driver = $this->driver($inner, $slept, maxDelaySeconds: 8.0);

        $response = $driver->chat($this->request());

        $this->assertSame('ok', $response->content);
        $this->assertSame([8.0], $slept);
    }

    /** stream() carries the twin logic and must give up on the same terms. */
    public function testStreamAlsoGivesUpOnACooldownBeyondTheBudget(): void
    {
        $slept = [];
        $inner = new ControllableDriver('fake', [new RateLimitException('rate limited', 3_600)]);
        $driver = $this->driver($inner, $slept, maxDelaySeconds: 8.0);

        try {
            iterator_to_array($driver->stream($this->request()));
            $this->fail('expected the rate limit to propagate without a doomed retry');
        } catch (RateLimitException) {
            // expected
        }

        $this->assertSame([], $slept);
        $this->assertSame(1, $inner->callCount);
    }

    public function testAnAbsentRetryAfterFallsBackToTheJitteredBackoff(): void
    {
        $slept = [];
        $inner = new ControllableDriver('fake', [new RateLimitException('rate limited', null)]);
        $driver = $this->driver($inner, $slept, baseDelaySeconds: 1.0);

        $driver->chat($this->request());

        $this->assertCount(1, $slept);
        $this->assertGreaterThanOrEqual(0.5, $slept[0], 'jitter floor of 50% of the exponential delay');
        $this->assertLessThanOrEqual(1.0, $slept[0], 'jitter ceiling of 100% of the exponential delay');
    }

    public function testARetryAfterOfZeroStillUsesTheBackoffInsteadOfBusyLooping(): void
    {
        $slept = [];
        $inner = new ControllableDriver('fake', [new RateLimitException('rate limited', 0)]);
        $driver = $this->driver($inner, $slept, baseDelaySeconds: 1.0);

        $driver->chat($this->request());

        $this->assertGreaterThan(0.0, $slept[0]);
    }

    public function testATypedRateLimitFailureIsRetryableEvenWithoutAWrappedGuzzleException(): void
    {
        // The caller must never have to read the exception message to learn
        // this was a rate limit: the type and its typed delay carry it.
        $slept = [];
        $inner = new ControllableDriver('fake', [new RateLimitException('rate limited', 1)]);
        $driver = $this->driver($inner, $slept);

        $response = $driver->chat($this->request());

        $this->assertSame('ok', $response->content);
        $this->assertSame(2, $inner->callCount);
    }

    public function testGivingUpRethrowsTheTypedExceptionWithItsDelayIntact(): void
    {
        $inner = new ControllableDriver('fake', [
            new RateLimitException('rate limited', 42),
            new RateLimitException('rate limited', 42),
        ]);
        $slept = [];
        $driver = $this->driver($inner, $slept, maxAttempts: 2);

        try {
            $driver->chat($this->request());
            $this->fail('expected the rate limit to propagate after the attempt budget ran out');
        } catch (RateLimitException $e) {
            $this->assertSame(42, $e->getRetryAfterSeconds());
            $this->assertSame(429, $e->getStatusCode());
        }
    }

    public function testNoRetryHappensOnceAFragmentHasBeenEmitted(): void
    {
        $slept = [];
        $inner = new PartialStreamDriver('fake', ['Hel', 'lo'], new RateLimitException('rate limited', 1));
        $driver = $this->driver($inner, $slept, maxAttempts: 5);

        $chunks = [];
        try {
            foreach ($driver->stream($this->request()) as $chunk) {
                $chunks[] = $chunk;
            }
            $this->fail('expected the mid-stream failure to propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('rate limited', $e->getMessage());
        }

        // Retrying here would replay "Hel", "lo" on top of what the user has
        // already seen — an emitted fragment can't be un-emitted.
        $this->assertSame(['Hel', 'lo'], $chunks);
        $this->assertSame(1, $inner->callCount, 'the stream was attempted exactly once');
        $this->assertSame([], $slept);
    }
}
