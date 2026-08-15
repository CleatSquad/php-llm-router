<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Driver;

use CleatSquad\LlmRouter\Contract\Driver\EmbeddingDriverInterface;
use CleatSquad\LlmRouter\DTO\CostEstimate;
use CleatSquad\LlmRouter\DTO\EmbeddingRequest;
use CleatSquad\LlmRouter\DTO\EmbeddingResponse;
use CleatSquad\LlmRouter\DTO\HealthStatus;
use CleatSquad\LlmRouter\Enum\DriverType;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Tries each EmbeddingDriverInterface in order, falling through when one is unavailable or embed() throws.
 * Priority order is caller-configured (e.g. a DB column), not decided here.
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
