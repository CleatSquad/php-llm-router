<?php

declare(strict_types=1);

namespace LlmRouter\Driver;

use LlmRouter\Contract\Driver\LLMDriverInterface;
use LlmRouter\DTO\CostEstimate;
use LlmRouter\DTO\HealthStatus;
use LlmRouter\DTO\LLMRequest;
use LlmRouter\DTO\LLMResponse;
use LlmRouter\Enum\DriverType;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Generator;
use Throwable;

/**
 * Decorates any LLMDriverInterface with automatic retries on transient
 * failures (connection errors, timeouts, HTTP 429, HTTP 5xx), using
 * exponential backoff. Non-transient failures (4xx other than 429 —
 * bad request, auth, not found, ...) propagate on the first attempt,
 * since retrying them would just fail the same way $maxAttempts times.
 *
 * Every driver in this package wraps its underlying Guzzle exception as
 * RuntimeException::getPrevious(), which is how retryability is judged
 * here without each driver needing to know about retries at all.
 */
final class RetryingDriver implements LLMDriverInterface
{
    public function __construct(
        private readonly LLMDriverInterface $inner,
        private readonly int $maxAttempts = 3,
        private readonly float $baseDelaySeconds = 0.5,
        private readonly float $maxDelaySeconds = 8.0,
    ) {}

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
                $this->sleep($this->delayForAttempt($attempt));
            }
        }
    }

    /**
     * A failure that happens before any chunk has reached the caller is
     * safe to retry (the caller has seen nothing yet, so the retried
     * attempt just replaces the failed one). A failure partway through
     * an already-flowing stream is NOT retried — some of that content
     * was already yielded out to the caller, and a fresh attempt would
     * either duplicate it or produce an inconsistent transcript, so it
     * propagates immediately regardless of attempts remaining.
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
                $this->sleep($this->delayForAttempt($attempt));
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

    private function delayForAttempt(int $attempt): float
    {
        return min($this->maxDelaySeconds, $this->baseDelaySeconds * (2 ** ($attempt - 1)));
    }

    private function sleep(float $seconds): void
    {
        usleep((int) ($seconds * 1_000_000));
    }
}
