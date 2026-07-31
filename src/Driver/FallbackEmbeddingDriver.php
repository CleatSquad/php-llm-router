<?php

declare(strict_types=1);

namespace LlmRouter\Driver;

use LlmRouter\Contract\Driver\EmbeddingDriverInterface;
use LlmRouter\DTO\CostEstimate;
use LlmRouter\DTO\EmbeddingRequest;
use LlmRouter\DTO\EmbeddingResponse;
use LlmRouter\DTO\HealthStatus;
use LlmRouter\Enum\DriverType;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Decorates an ordered list of EmbeddingDriverInterface — always tries the
 * first (the "main system") first, falling through to the next one only
 * when a driver is unavailable or its embed() call throws, in the given
 * order. The order itself carries the priority; callers configure it
 * (e.g. from a DB-backed priority column) rather than this class picking
 * one.
 */
final class FallbackEmbeddingDriver implements EmbeddingDriverInterface
{
    /**
     * @param EmbeddingDriverInterface[] $drivers Highest priority first. At least one required.
     */
    public function __construct(private readonly array $drivers)
    {
        if ($this->drivers === []) {
            throw new InvalidArgumentException('FallbackEmbeddingDriver requires at least one driver.');
        }
    }

    public function getId(): string
    {
        return 'fallback:' . implode(',', array_map(static fn (EmbeddingDriverInterface $d) => $d->getId(), $this->drivers));
    }

    public function getName(): string
    {
        return 'Fallback (' . $this->drivers[0]->getName() . ')';
    }

    public function getType(): DriverType
    {
        return DriverType::EMBEDDING;
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
            'chain' => array_map(static fn (EmbeddingDriverInterface $d) => $d->getId(), $this->drivers),
        ];
    }

    public function embed(EmbeddingRequest $request): EmbeddingResponse
    {
        $errors = [];

        foreach ($this->drivers as $driver) {
            if (!$driver->isAvailable()) {
                $errors[] = $driver->getId() . ': not available';
                continue;
            }

            try {
                return $driver->embed($request);
            } catch (Throwable $e) {
                $errors[] = $driver->getId() . ': ' . $e->getMessage();
            }
        }

        throw new RuntimeException('All embedding drivers failed: ' . implode(' | ', $errors));
    }

    /**
     * @return string[]
     */
    public function getModels(): array
    {
        return $this->drivers[0]->getModels();
    }

    public function estimateCost(EmbeddingRequest $request): CostEstimate
    {
        return $this->drivers[0]->estimateCost($request);
    }
}
