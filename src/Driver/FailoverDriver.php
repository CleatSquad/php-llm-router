<?php

declare(strict_types=1);

namespace LlmRouter\Driver;

use Generator;
use InvalidArgumentException;
use LlmRouter\Contract\Driver\LLMDriverInterface;
use LlmRouter\Contract\RoutingStrategyInterface;
use LlmRouter\DTO\CostEstimate;
use LlmRouter\DTO\HealthStatus;
use LlmRouter\DTO\LLMRequest;
use LlmRouter\DTO\LLMResponse;
use LlmRouter\Enum\DriverType;
use LlmRouter\Exception\AllDriversFailedException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Decorates a RoutingStrategyInterface + a list of drivers with real
 * fail-over: on a RuntimeException from the selected driver, excludes it
 * and asks the strategy for the next candidate, instead of the caller
 * having to re-implement that loop by hand around select().
 *
 * Only RuntimeException triggers fail-over — every driver in this package
 * exclusively throws RuntimeException from chat()/stream() on call failure
 * (transport or API-level), so anything else (TypeError, Error, ...) is a
 * programming/environment defect that switching providers cannot fix and
 * is left to propagate immediately.
 *
 * Composes for free with CircuitBreakerDriver: if each candidate is already
 * wrapped in one, its recordFailure() updates the breaker's store before
 * this class asks the strategy for the next candidate, so isAvailable()
 * already reflects the failure on the very next iteration.
 */
final class FailoverDriver implements LLMDriverInterface
{
    /** @var callable(RuntimeException, LLMDriverInterface): bool */
    private $shouldFailover;

    /**
     * @param LLMDriverInterface[] $drivers Candidates to fail over across. At least one required.
     * @param callable(RuntimeException, LLMDriverInterface): bool|null $shouldFailover
     *   Decides whether a given failure should trigger a fail-over to the
     *   next candidate (true) or propagate immediately (false). Defaults
     *   to always failing over — for a library whose purpose is
     *   availability, an unnecessary extra attempt is cheaper than giving
     *   up while another provider would have answered.
     */
    public function __construct(
        private readonly RoutingStrategyInterface $strategy,
        private readonly array $drivers,
        ?callable $shouldFailover = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        if ($this->drivers === []) {
            throw new InvalidArgumentException('FailoverDriver requires at least one driver.');
        }

        $this->shouldFailover = $shouldFailover
            ?? static fn (RuntimeException $e, LLMDriverInterface $d): bool => true;
    }

    public function getId(): string
    {
        return 'failover:' . implode(',', array_map(
            static fn (LLMDriverInterface $d): string => $d->getId(),
            $this->drivers
        ));
    }

    public function getName(): string
    {
        return 'Failover (' . $this->drivers[0]->getName() . ')';
    }

    public function getType(): DriverType
    {
        return DriverType::LLM;
    }

    public function isAvailable(): bool
    {
        foreach ($this->drivers as $driver) {
            if ($driver->isAvailable()) {
                return true;
            }
        }

        return false;
    }

    public function healthCheck(): HealthStatus
    {
        foreach ($this->drivers as $driver) {
            $status = $driver->healthCheck();
            if ($status->isHealthy()) {
                return $status;
            }
        }

        return $this->drivers[0]->healthCheck();
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return [
            'chain' => array_map(static fn (LLMDriverInterface $d): string => $d->getId(), $this->drivers),
        ];
    }

    public function chat(LLMRequest $request): LLMResponse
    {
        $remaining = $this->drivers;
        $failures = [];

        while ($remaining !== []) {
            $driver = $this->strategy->select($request, $remaining);

            try {
                return $driver->chat($request);
            } catch (RuntimeException $e) {
                if (!($this->shouldFailover)($e, $driver)) {
                    throw $e;
                }

                $failures[] = ['driverId' => $driver->getId(), 'exception' => $e];
                $this->logger?->notice('llm_router.failover', [
                    'driver_id' => $driver->getId(),
                    'error' => $e->getMessage(),
                ]);
                $remaining = self::without($remaining, $driver);
            }
        }

        $this->logger?->error('llm_router.all_drivers_failed', ['failures' => $failures]);
        throw new AllDriversFailedException($failures);
    }

    /**
     * Fails over only before the first chunk reaches the caller: once a
     * fragment has been yielded it can't be un-yielded, so switching
     * providers mid-stream would produce duplicated or inconsistent
     * output. A failure after that point propagates immediately — the
     * caller (not this driver) decides how to handle an already-partial
     * response (show it as truncated, offer a manual retry, ...).
     *
     * @return Generator<int, string, mixed, ?array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>>
     */
    public function stream(LLMRequest $request): Generator
    {
        $remaining = $this->drivers;
        $failures = [];

        while ($remaining !== []) {
            $driver = $this->strategy->select($request, $remaining);
            $yieldedAny = false;

            try {
                $inner = $driver->stream($request);
                foreach ($inner as $chunk) {
                    $yieldedAny = true;
                    yield $chunk;
                }
                return $inner->getReturn();
            } catch (RuntimeException $e) {
                if ($yieldedAny || !($this->shouldFailover)($e, $driver)) {
                    throw $e;
                }

                $failures[] = ['driverId' => $driver->getId(), 'exception' => $e];
                $this->logger?->notice('llm_router.failover', [
                    'driver_id' => $driver->getId(),
                    'error' => $e->getMessage(),
                ]);
                $remaining = self::without($remaining, $driver);
            }
        }

        $this->logger?->error('llm_router.all_drivers_failed', ['failures' => $failures]);
        throw new AllDriversFailedException($failures);
    }

    /**
     * @return string[]
     */
    public function getModels(): array
    {
        return $this->drivers[0]->getModels();
    }

    public function supportsStreaming(): bool
    {
        return $this->drivers[0]->supportsStreaming();
    }

    public function supportsTools(): bool
    {
        return $this->drivers[0]->supportsTools();
    }

    public function supportsVision(): bool
    {
        return $this->drivers[0]->supportsVision();
    }

    public function supportsReasoning(): bool
    {
        return $this->drivers[0]->supportsReasoning();
    }

    /**
     * Best-effort/pre-flight only: reflects the driver the strategy would
     * pick right now for this request, which may differ from whichever
     * driver ends up actually serving it if a fail-over happens during the
     * real chat()/stream() call.
     */
    public function estimateCost(LLMRequest $request): CostEstimate
    {
        return $this->strategy->select($request, $this->drivers)->estimateCost($request);
    }

    /**
     * @param LLMDriverInterface[] $drivers
     * @return LLMDriverInterface[]
     */
    private static function without(array $drivers, LLMDriverInterface $exclude): array
    {
        return array_values(array_filter(
            $drivers,
            static fn (LLMDriverInterface $d): bool => $d->getId() !== $exclude->getId()
        ));
    }
}
