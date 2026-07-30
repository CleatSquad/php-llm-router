<?php

declare(strict_types=1);

namespace Concio\LlmRouter\Tests\DTO;

use Concio\LlmRouter\DTO\HealthState;
use Concio\LlmRouter\DTO\HealthStatus;
use PHPUnit\Framework\TestCase;

final class HealthStatusTest extends TestCase
{
    public function testHealthyFactoryIsHealthy(): void
    {
        $status = HealthStatus::healthy(42, 'all good');

        $this->assertTrue($status->isHealthy());
        $this->assertSame(HealthState::HEALTHY, $status->status);
        $this->assertSame(42, $status->latencyMs);
        $this->assertSame('all good', $status->message);
    }

    public function testUnhealthyFactoryIsNotHealthy(): void
    {
        $status = HealthStatus::unhealthy('connection refused');

        $this->assertFalse($status->isHealthy());
        $this->assertSame(HealthState::UNHEALTHY, $status->status);
    }

    public function testUnknownFactoryIsNotHealthy(): void
    {
        $status = HealthStatus::unknown();

        $this->assertFalse($status->isHealthy());
        $this->assertSame(HealthState::UNKNOWN, $status->status);
    }
}
