<?php

declare(strict_types=1);

namespace LlmRouter\Tests\Driver;

use LlmRouter\Contract\Driver\AudioDriverInterface;
use LlmRouter\Driver\FallbackAudioDriver;
use LlmRouter\DTO\AudioTranscriptionRequest;
use LlmRouter\DTO\AudioTranscriptionResponse;
use LlmRouter\DTO\CostEstimate;
use LlmRouter\DTO\HealthState;
use LlmRouter\DTO\HealthStatus;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FallbackAudioDriverTest extends TestCase
{
    public function testThrowsWhenGivenNoDrivers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FallbackAudioDriver([]);
    }

    public function testTranscribeUsesThePrimaryDriverWhenAvailable(): void
    {
        $primary = $this->createMock(AudioDriverInterface::class);
        $primary->method('isAvailable')->willReturn(true);
        $primary->method('getId')->willReturn('primary');
        $primary->expects($this->once())->method('transcribe')->willReturn(
            new AudioTranscriptionResponse('bonjour', 'whisper-1', 'fr', 1.0, 0.0001, 5)
        );

        $secondary = $this->createMock(AudioDriverInterface::class);
        $secondary->expects($this->never())->method('transcribe');

        $driver = new FallbackAudioDriver([$primary, $secondary]);
        $response = $driver->transcribe(new AudioTranscriptionRequest('audio-bytes'));

        $this->assertSame('bonjour', $response->text);
    }

    public function testTranscribeFallsBackWhenThePrimaryThrows(): void
    {
        $primary = $this->createMock(AudioDriverInterface::class);
        $primary->method('isAvailable')->willReturn(true);
        $primary->method('getId')->willReturn('primary');
        $primary->method('transcribe')->willThrowException(new RuntimeException('rate limited'));

        $secondary = $this->createMock(AudioDriverInterface::class);
        $secondary->method('isAvailable')->willReturn(true);
        $secondary->method('getId')->willReturn('secondary');
        $secondary->expects($this->once())->method('transcribe')->willReturn(
            new AudioTranscriptionResponse('salut', 'whisper-large-v3', 'fr', 1.0, 0.00003, 5)
        );

        $driver = new FallbackAudioDriver([$primary, $secondary]);
        $response = $driver->transcribe(new AudioTranscriptionRequest('audio-bytes'));

        $this->assertSame('salut', $response->text);
    }

    public function testTranscribeThrowsWithAggregatedErrorsWhenEveryDriverFails(): void
    {
        $primary = $this->createMock(AudioDriverInterface::class);
        $primary->method('isAvailable')->willReturn(false);
        $primary->method('getId')->willReturn('primary');

        $secondary = $this->createMock(AudioDriverInterface::class);
        $secondary->method('isAvailable')->willReturn(true);
        $secondary->method('getId')->willReturn('secondary');
        $secondary->method('transcribe')->willThrowException(new RuntimeException('quota exceeded'));

        $driver = new FallbackAudioDriver([$primary, $secondary]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/primary: not available.*secondary: quota exceeded/s');
        $driver->transcribe(new AudioTranscriptionRequest('audio-bytes'));
    }

    public function testHealthCheckReturnsTheFirstHealthyDriversStatus(): void
    {
        $primary = $this->createMock(AudioDriverInterface::class);
        $primary->method('healthCheck')->willReturn(HealthStatus::unhealthy('down'));

        $secondary = $this->createMock(AudioDriverInterface::class);
        $secondary->method('healthCheck')->willReturn(HealthStatus::healthy(10, 'ok'));

        $driver = new FallbackAudioDriver([$primary, $secondary]);
        $status = $driver->healthCheck();

        $this->assertSame(HealthState::HEALTHY, $status->status);
    }

    public function testGetModelsAndEstimateCostDelegateToThePrimaryDriver(): void
    {
        $primary = $this->createMock(AudioDriverInterface::class);
        $primary->method('getModels')->willReturn(['whisper-1']);
        $primary->method('estimateCost')->willReturn(new CostEstimate(0.0, 0.0, 0, 0.0001));

        $secondary = $this->createMock(AudioDriverInterface::class);
        $secondary->expects($this->never())->method('getModels');

        $driver = new FallbackAudioDriver([$primary, $secondary]);

        $this->assertSame(['whisper-1'], $driver->getModels());
        $this->assertSame(0.0001, $driver->estimateCost(new AudioTranscriptionRequest('bytes'))->estimatedCostUsd);
    }

    public function testGetMetadataListsTheFullChainInOrder(): void
    {
        $primary = $this->createMock(AudioDriverInterface::class);
        $primary->method('getId')->willReturn('primary');

        $secondary = $this->createMock(AudioDriverInterface::class);
        $secondary->method('getId')->willReturn('secondary');

        $driver = new FallbackAudioDriver([$primary, $secondary]);

        $this->assertSame(['primary', 'secondary'], $driver->getMetadata()['chain']);
    }
}
