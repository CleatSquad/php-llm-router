<?php

declare(strict_types=1);

namespace LlmRouter\Tests\Driver;

use LlmRouter\Driver\RetryingDriver;
use LlmRouter\DTO\LLMRequest;
use LlmRouter\Exception\RateLimitException;
use LlmRouter\Tests\Fixtures\ControllableDriver;
use LlmRouter\Tests\Fixtures\PartialStreamDriver;
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

    public function testTheProviderDelayIsStillCappedByMaxDelaySeconds(): void
    {
        $slept = [];
        $inner = new ControllableDriver('fake', [new RateLimitException('rate limited', 3_600)]);
        $driver = $this->driver($inner, $slept, maxDelaySeconds: 8.0);

        $driver->chat($this->request());

        $this->assertSame([8.0], $slept, 'an absurd Retry-After must not park the caller for an hour');
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
