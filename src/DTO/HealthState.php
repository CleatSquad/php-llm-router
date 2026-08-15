<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\DTO;

/**
 * Health state enumeration for drivers.
 */
enum HealthState: string
{
    case HEALTHY = 'healthy';
    case DEGRADED = 'degraded';
    case UNHEALTHY = 'unhealthy';
    case UNKNOWN = 'unknown';
}
