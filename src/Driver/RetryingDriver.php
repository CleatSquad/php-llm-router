<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Driver;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\DTO\CostEstimate;
use CleatSquad\LlmRouter\DTO\HealthStatus;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\DTO\LLMResponse;
use CleatSquad\LlmRouter\Enum\DriverType;
use CleatSquad\LlmRouter\Exception\RateLimitException;
use Generator;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Throwable;

/**
 * Retries transient failures (connection errors, timeouts, HTTP 429/5xx) with exponential backoff; other 4xx propagate immediately since retrying would just fail the same way.
 * Retryability is judged from the wrapped Guzzle exception on RuntimeException::getPrevious(), which every driver in this package sets.
 */
final class RetryingDriver implements LLMDriverInterface
{
    /** @var callable(float): void */
    private $sleeper;

    /**
     * @param callable(float): void|null $sleeper How to wait between attempts.
     *   Defaults to usleep(). Injectable so a test can assert on the schedule
     *   the driver decided (a 30s Retry-After must actually mean 30s) without
     *   waiting it out, and so an event-loop application can yield instead of
     *   blocking its worker.
     */
    public function __construct(
        private readonly LLMDriverInterface $inner,
        private readonly int $maxAttempts = 3,
        private readonly float $baseDelaySeconds = 0.5,
        private readonly float $maxDelaySeconds = 8.0,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
    }

    public function getId(): string
    {
        return $this->inner->getId();
    }

    public function getName(): string
    {
        return $this->inner->getName();
    }

    public function getType(): DriverType
    {
        return $this->inner->getType();
    }

    public function isAvailable(): bool
    {
        return $this->inner->isAvailable();
    }

    public function healthCheck(): HealthStatus
    {
        return $this->inner->healthCheck();
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->inner->getMetadata();
    }

    public function chat(LLMRequest $request): LLMResponse
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return $this->inner->chat($request);
            } catch (Throwable $e) {
                if ($attempt >= $this->maxAttempts || !$this->isRetryable($e)) {
                    throw $e;
                }
                $this->sleep($this->delayFor($e, $attempt));
            }
        }
    }

    /**
     * Only retries before any chunk reached the caller; once streaming has started a failure propagates immediately to avoid duplicating or corrupting already-yielded output.
     *
     * @return Generator<int, string, mixed, ?array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>>
     */
    public function stream(LLMRequest $request): Generator
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            $yieldedAny = false;
            try {
                $inner = $this->inner->stream($request);
                foreach ($inner as $chunk) {
                    $yieldedAny = true;
                    yield $chunk;
                }
                return $inner->getReturn();
            } catch (Throwable $e) {
                if ($yieldedAny || $attempt >= $this->maxAttempts || !$this->isRetryable($e)) {
                    throw $e;
                }
                $this->sleep($this->delayFor($e, $attempt));
            }
        }
    }

    /**
     * @return string[]
     */
    public function getModels(): array
    {
        return $this->inner->getModels();
    }

    public function supportsStreaming(): bool
    {
        return $this->inner->supportsStreaming();
    }

    public function supportsTools(): bool
    {
        return $this->inner->supportsTools();
    }

    public function supportsVision(): bool
    {
        return $this->inner->supportsVision();
    }

    public function supportsReasoning(): bool
    {
        return $this->inner->supportsReasoning();
    }

    public function estimateCost(LLMRequest $request): CostEstimate
    {
        return $this->inner->estimateCost($request);
    }

    private function isRetryable(Throwable $e): bool
    {
        if ($e instanceof RateLimitException) {
            return true;
        }

        $previous = $e->getPrevious();

        if ($previous instanceof ConnectException) {
            return true;
        }

        if ($previous instanceof RequestException) {
            $response = $previous->getResponse();
            if ($response === null) {
                return true;
            }
            $status = $response->getStatusCode();
            return $status === 429 || $status >= 500;
        }

        return false;
    }

    /**
     * How long to wait before the next attempt.
     *
     * When the provider itself said how long to back off — a 429 whose
     * Retry-After the driver surfaced as a typed RateLimitException — that
     * value replaces the computed backoff outright: it is the only figure
     * that actually reflects when the quota frees up, and retrying earlier
     * only earns another 429. It is still capped by $maxDelaySeconds so a
     * hostile or absurd header can't park the caller for an hour, and a
     * Retry-After of 0 still yields the exponential delay rather than a
     * busy-loop.
     */
    private function delayFor(Throwable $e, int $attempt): float
    {
        $providerDelay = $e instanceof RateLimitException ? $e->getRetryAfterSeconds() : null;

        if ($providerDelay !== null && $providerDelay > 0) {
            return min((float) $providerDelay, $this->maxDelaySeconds);
        }

        return $this->delayForAttempt($attempt);
    }

    /**
     * Decorrelated jitter: 50-100% of the exponential delay, so workers
     * retrying the same outage don't resync onto one schedule.
     */
    private function delayForAttempt(int $attempt): float
    {
        $exp = min($this->maxDelaySeconds, $this->baseDelaySeconds * (2 ** ($attempt - 1)));
        return $exp * (0.5 + (random_int(0, 500) / 1000));
    }

    private function sleep(float $seconds): void
    {
        ($this->sleeper)($seconds);
    }
}
