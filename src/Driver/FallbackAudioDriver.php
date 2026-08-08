<?php

declare(strict_types=1);

namespace LlmRouter\Driver;

use InvalidArgumentException;
use LlmRouter\Contract\Driver\AudioDriverInterface;
use LlmRouter\DTO\AudioTranscriptionRequest;
use LlmRouter\DTO\AudioTranscriptionResponse;
use LlmRouter\DTO\CostEstimate;
use LlmRouter\DTO\HealthStatus;
use LlmRouter\Enum\DriverType;
use RuntimeException;
use Throwable;

/**
 * Same role as FallbackEmbeddingDriver, for audio transcription: tries drivers in order, falling through only on unavailability or a transcribe() failure.
 */
final class FallbackAudioDriver implements AudioDriverInterface
{
    /**
     * @param AudioDriverInterface[] $drivers Highest priority first. At least one required.
     */
    public function __construct(private readonly array $drivers)
    {
        if ($this->drivers === []) {
            throw new InvalidArgumentException('FallbackAudioDriver requires at least one driver.');
        }
    }

    public function getId(): string
    {
        return 'fallback:' . implode(',', array_map(static fn (AudioDriverInterface $d) => $d->getId(), $this->drivers));
    }

    public function getName(): string
    {
        return 'Fallback (' . $this->drivers[0]->getName() . ')';
    }

    public function getType(): DriverType
    {
        return DriverType::AUDIO;
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
            'chain' => array_map(static fn (AudioDriverInterface $d) => $d->getId(), $this->drivers),
        ];
    }

    public function transcribe(AudioTranscriptionRequest $request): AudioTranscriptionResponse
    {
        $errors = [];

        foreach ($this->drivers as $driver) {
            if (!$driver->isAvailable()) {
                $errors[] = $driver->getId() . ': not available';
                continue;
            }

            try {
                return $driver->transcribe($request);
            } catch (Throwable $e) {
                $errors[] = $driver->getId() . ': ' . $e->getMessage();
            }
        }

        throw new RuntimeException('All audio drivers failed: ' . implode(' | ', $errors));
    }

    /**
     * @return string[]
     */
    public function getModels(): array
    {
        return $this->drivers[0]->getModels();
    }

    public function estimateCost(AudioTranscriptionRequest $request): CostEstimate
    {
        return $this->drivers[0]->estimateCost($request);
    }
}
