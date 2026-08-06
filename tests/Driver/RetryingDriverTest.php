<?php

declare(strict_types=1);

namespace LlmRouter\Tests\Driver;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use LlmRouter\Contract\Driver\LLMDriverInterface;
use LlmRouter\DTO\CostEstimate;
use LlmRouter\DTO\HealthStatus;
use LlmRouter\Driver\RetryingDriver;
use LlmRouter\DTO\LLMRequest;
use LlmRouter\DTO\LLMResponse;
use LlmRouter\Enum\DriverType;
use LlmRouter\Tests\Fixtures\ControllableDriver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RetryingDriverTest extends TestCase
{
    private function request(): LLMRequest
    {
        return new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]);
    }

    private static function wrapped(\Throwable $guzzleException, string $message = 'request failed'): RuntimeException
    {
        return new RuntimeException($message, 0, $guzzleException);
    }

    private static function serverError(int $status = 500): RuntimeException
    {
        $request = new Psr7Request('POST', 'http://example.test');
        return self::wrapped(new RequestException('server error', $request, new Psr7Response($status)));
    }

    private static function clientError(int $status = 400): RuntimeException
    {
        $request = new Psr7Request('POST', 'http://example.test');
        return self::wrapped(new RequestException('client error', $request, new Psr7Response($status)));
    }

    private static function connectError(): RuntimeException
    {
        $request = new Psr7Request('POST', 'http://example.test');
        return self::wrapped(new ConnectException('connection timed out', $request));
    }

    public function testSucceedsOnFirstAttemptWithoutRetrying(): void
    {
        $inner = new ControllableDriver('fake');
        $driver = new RetryingDriver($inner, maxAttempts: 3, baseDelaySeconds: 0.001);

        $response = $driver->chat($this->request());

        $this->assertSame('ok', $response->content);
        $this->assertSame(1, $inner->callCount);
    }

    public function testRetriesTransientFailuresAndEventuallySucceeds(): void
    {
        $inner = new ControllableDriver('fake', [self::serverError(), self::connectError(), null]);
        $driver = new RetryingDriver($inner, maxAttempts: 3, baseDelaySeconds: 0.001);

        $response = $driver->chat($this->request());

        $this->assertSame('ok', $response->content);
        $this->assertSame(3, $inner->callCount);
    }

    public function testGivesUpAfterMaxAttempts(): void
    {
        $failure = self::serverError();
        $inner = new ControllableDriver('fake', [$failure, $failure, $failure]);
        $driver = new RetryingDriver($inner, maxAttempts: 3, baseDelaySeconds: 0.001);

        $this->expectException(RuntimeException::class);
        try {
            $driver->chat($this->request());
        } finally {
            $this->assertSame(3, $inner->callCount);
        }
    }

    public function testDoesNotRetryNonTransientClientErrors(): void
    {
        $inner = new ControllableDriver('fake', [self::clientError(401)]);
        $driver = new RetryingDriver($inner, maxAttempts: 3, baseDelaySeconds: 0.001);

        try {
            $driver->chat($this->request());
            $this->fail('expected the non-retryable error to propagate');
        } catch (RuntimeException) {
        }

        $this->assertSame(1, $inner->callCount, 'a 401 must not be retried');
    }

    public function testRetries429TooManyRequests(): void
    {
        $inner = new ControllableDriver('fake', [self::serverError(429), null]);
        $driver = new RetryingDriver($inner, maxAttempts: 3, baseDelaySeconds: 0.001);

        $response = $driver->chat($this->request());

        $this->assertSame('ok', $response->content);
        $this->assertSame(2, $inner->callCount);
    }

    public function testUnrecognizedExceptionShapeIsNotRetried(): void
    {
        $inner = new ControllableDriver('fake', [new RuntimeException('mystery failure')]);
        $driver = new RetryingDriver($inner, maxAttempts: 3, baseDelaySeconds: 0.001);

        try {
            $driver->chat($this->request());
            $this->fail('expected the exception to propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('mystery failure', $e->getMessage());
        }

        $this->assertSame(1, $inner->callCount);
    }

    public function testStreamRetriesWhenNoChunkWasYieldedYet(): void
    {
        $inner = new ControllableDriver('fake', [self::serverError(), null]);
        $driver = new RetryingDriver($inner, maxAttempts: 3, baseDelaySeconds: 0.001);

        $chunks = iterator_to_array($driver->stream($this->request()));

        $this->assertSame(['ok'], $chunks);
        $this->assertSame(2, $inner->callCount);
    }

    public function testStreamDoesNotRetryOnceContentHasAlreadyBeenYielded(): void
    {
        $inner = new class implements LLMDriverInterface {
            public int $callCount = 0;

            public function getId(): string { return 'fake'; }
            public function getName(): string { return 'fake'; }
            public function getType(): DriverType { return DriverType::LLM; }
            public function isAvailable(): bool { return true; }
            public function healthCheck(): HealthStatus { return HealthStatus::healthy(); }
            public function getMetadata(): array { return []; }
            public function getModels(): array { return ['fake-model']; }
            public function supportsStreaming(): bool { return true; }
            public function supportsTools(): bool { return false; }
            public function supportsVision(): bool { return false; }
            public function supportsReasoning(): bool { return false; }
            public function estimateCost(LLMRequest $request): CostEstimate { return CostEstimate::free(); }

            public function chat(LLMRequest $request): LLMResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function stream(LLMRequest $request): \Generator
            {
                $this->callCount++;
                yield 'partial ';

                $psrRequest = new Psr7Request('POST', 'http://example.test');
                throw new RuntimeException('mid-stream failure', 0, new RequestException(
                    'server error',
                    $psrRequest,
                    new Psr7Response(500)
                ));
            }
        };
        $driver = new RetryingDriver($inner, maxAttempts: 3, baseDelaySeconds: 0.001);

        $chunks = [];
        try {
            foreach ($driver->stream($this->request()) as $chunk) {
                $chunks[] = $chunk;
            }
            $this->fail('expected the mid-stream failure to propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('mid-stream failure', $e->getMessage());
        }

        $this->assertSame(['partial '], $chunks);
        $this->assertSame(1, $inner->callCount, 'must not retry once content has already reached the caller');
    }

    public function testBackoffIsJitteredWithinFiftyToOneHundredPercentOfTheExponentialDelay(): void
    {
        // exp = min(2.0, 0.3 * 2^0) = 0.3 -> jittered range [0.15, 0.3]
        $inner = new ControllableDriver('fake', [self::serverError(), null]);
        $driver = new RetryingDriver($inner, maxAttempts: 2, baseDelaySeconds: 0.3, maxDelaySeconds: 2.0);

        $start = microtime(true);
        $driver->chat($this->request());
        $elapsed = microtime(true) - $start;

        $this->assertGreaterThanOrEqual(0.14, $elapsed);
        $this->assertLessThan(0.5, $elapsed, 'jitter must not exceed 100% of the exponential delay by more than scheduling noise');
    }

    public function testBackoffVariesAcrossRunsInsteadOfBeingFixed(): void
    {
        // A fixed (non-jittered) delay clusters tightly (a few ms of
        // scheduling noise); real jitter spans up to half of the 0.3s
        // exponential delay, so the spread across runs is the signal.
        $elapsedTimes = [];
        for ($i = 0; $i < 8; $i++) {
            $inner = new ControllableDriver('fake', [self::serverError(), null]);
            $driver = new RetryingDriver($inner, maxAttempts: 2, baseDelaySeconds: 0.3, maxDelaySeconds: 2.0);

            $start = microtime(true);
            $driver->chat($this->request());
            $elapsedTimes[] = microtime(true) - $start;
        }

        $spread = max($elapsedTimes) - min($elapsedTimes);
        $this->assertGreaterThan(0.05, $spread, 'a fixed delay would not spread this much across runs');
    }
}
