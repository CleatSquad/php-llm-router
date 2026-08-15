<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Driver;

use CleatSquad\LlmRouter\Contract\Driver\EmbeddingDriverInterface;
use CleatSquad\LlmRouter\Driver\FallbackEmbeddingDriver;
use CleatSquad\LlmRouter\DTO\CostEstimate;
use CleatSquad\LlmRouter\DTO\EmbeddingRequest;
use CleatSquad\LlmRouter\DTO\EmbeddingResponse;
use CleatSquad\LlmRouter\DTO\HealthState;
use CleatSquad\LlmRouter\DTO\HealthStatus;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FallbackEmbeddingDriverTest extends TestCase
{
    public function testThrowsWhenGivenNoDrivers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FallbackEmbeddingDriver([]);
    }

    public function testEmbedUsesThePrimaryDriverWhenAvailable(): void
    {
        $primary = $this->createMock(EmbeddingDriverInterface::class);
        $primary->method('isAvailable')->willReturn(true);
        $primary->method('getId')->willReturn('primary');
        $primary->expects($this->once())->method('embed')->willReturn(
            new EmbeddingResponse([[0.1]], 'primary-model', 1, 1, 0.0, 5)
        );

        $secondary = $this->createMock(EmbeddingDriverInterface::class);
        $secondary->expects($this->never())->method('embed');

        $driver = new FallbackEmbeddingDriver([$primary, $secondary]);
        $response = $driver->embed(EmbeddingRequest::forText('hello'));

        $this->assertSame('primary-model', $response->model);
    }

    public function testEmbedFallsBackWhenThePrimaryIsUnavailable(): void
    {
        $primary = $this->createMock(EmbeddingDriverInterface::class);
        $primary->method('isAvailable')->willReturn(false);
        $primary->method('getId')->willReturn('primary');
        $primary->expects($this->never())->method('embed');

        $secondary = $this->createMock(EmbeddingDriverInterface::class);
        $secondary->method('isAvailable')->willReturn(true);
        $secondary->method('getId')->willReturn('secondary');
        $secondary->expects($this->once())->method('embed')->willReturn(
            new EmbeddingResponse([[0.2]], 'secondary-model', 1, 1, 0.0, 5)
        );

        $driver = new FallbackEmbeddingDriver([$primary, $secondary]);
        $response = $driver->embed(EmbeddingRequest::forText('hello'));

        $this->assertSame('secondary-model', $response->model);
    }

    public function testEmbedFallsBackWhenThePrimaryThrows(): void
    {
        $primary = $this->createMock(EmbeddingDriverInterface::class);
        $primary->method('isAvailable')->willReturn(true);
        $primary->method('getId')->willReturn('primary');
        $primary->method('embed')->willThrowException(new RuntimeException('rate limited'));

        $secondary = $this->createMock(EmbeddingDriverInterface::class);
        $secondary->method('isAvailable')->willReturn(true);
        $secondary->method('getId')->willReturn('secondary');
        $secondary->expects($this->once())->method('embed')->willReturn(
            new EmbeddingResponse([[0.3]], 'secondary-model', 1, 1, 0.0, 5)
        );

        $driver = new FallbackEmbeddingDriver([$primary, $secondary]);
        $response = $driver->embed(EmbeddingRequest::forText('hello'));

        $this->assertSame('secondary-model', $response->model);
    }

    public function testEmbedThrowsWithAggregatedErrorsWhenEveryDriverFails(): void
    {
        $primary = $this->createMock(EmbeddingDriverInterface::class);
        $primary->method('isAvailable')->willReturn(false);
        $primary->method('getId')->willReturn('primary');

        $secondary = $this->createMock(EmbeddingDriverInterface::class);
        $secondary->method('isAvailable')->willReturn(true);
        $secondary->method('getId')->willReturn('secondary');
        $secondary->method('embed')->willThrowException(new RuntimeException('quota exceeded'));

        $driver = new FallbackEmbeddingDriver([$primary, $secondary]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/primary: not available.*secondary: quota exceeded/s');
        $driver->embed(EmbeddingRequest::forText('hello'));
    }

    public function testIsAvailableIsTrueIfAnyDriverIsAvailable(): void
    {
        $primary = $this->createMock(EmbeddingDriverInterface::class);
        $primary->method('isAvailable')->willReturn(false);

        $secondary = $this->createMock(EmbeddingDriverInterface::class);
        $secondary->method('isAvailable')->willReturn(true);

        $driver = new FallbackEmbeddingDriver([$primary, $secondary]);

        $this->assertTrue($driver->isAvailable());
    }

    public function testIsAvailableIsFalseWhenNoDriverIsAvailable(): void
    {
        $primary = $this->createMock(EmbeddingDriverInterface::class);
        $primary->method('isAvailable')->willReturn(false);

        $driver = new FallbackEmbeddingDriver([$primary]);

        $this->assertFalse($driver->isAvailable());
    }

    public function testHealthCheckReturnsTheFirstHealthyDriversStatus(): void
    {
        $primary = $this->createMock(EmbeddingDriverInterface::class);
        $primary->method('healthCheck')->willReturn(HealthStatus::unhealthy('down'));

        $secondary = $this->createMock(EmbeddingDriverInterface::class);
        $secondary->method('healthCheck')->willReturn(HealthStatus::healthy(10, 'ok'));

        $driver = new FallbackEmbeddingDriver([$primary, $secondary]);
        $status = $driver->healthCheck();

        $this->assertSame(HealthState::HEALTHY, $status->status);
    }

    public function testGetModelsAndEstimateCostDelegateToThePrimaryDriver(): void
    {
        $primary = $this->createMock(EmbeddingDriverInterface::class);
        $primary->method('getModels')->willReturn(['primary-model']);
        $primary->method('estimateCost')->willReturn(new CostEstimate(0.0001, 0.0, 10, 0.000001));

        $secondary = $this->createMock(EmbeddingDriverInterface::class);
        $secondary->expects($this->never())->method('getModels');

        $driver = new FallbackEmbeddingDriver([$primary, $secondary]);

        $this->assertSame(['primary-model'], $driver->getModels());
        $this->assertSame(0.000001, $driver->estimateCost(EmbeddingRequest::forText('hi'))->estimatedCostUsd);
    }

    public function testGetMetadataListsTheFullChainInOrder(): void
    {
        $primary = $this->createMock(EmbeddingDriverInterface::class);
        $primary->method('getId')->willReturn('primary');

        $secondary = $this->createMock(EmbeddingDriverInterface::class);
        $secondary->method('getId')->willReturn('secondary');

        $driver = new FallbackEmbeddingDriver([$primary, $secondary]);

        $this->assertSame(['primary', 'secondary'], $driver->getMetadata()['chain']);
    }
}
