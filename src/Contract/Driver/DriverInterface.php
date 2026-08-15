<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Contract\Driver;

use CleatSquad\LlmRouter\DTO\HealthStatus;
use CleatSquad\LlmRouter\Enum\DriverType;

interface DriverInterface
{
    /**
     * Get the unique identifier of the driver instance.
     */
    public function getId(): string;

    /**
     * Get the human-readable name of the driver.
     */
    public function getName(): string;

    /**
     * Get the driver type (LLM, AGENT, MCP, etc.).
     */
    public function getType(): DriverType;

    /**
     * Check if the driver is currently available/operational.
     */
    public function isAvailable(): bool;

    /**
     * Perform a health check and return detailed status.
     */
    public function healthCheck(): HealthStatus;

    /**
     * Get driver metadata (version, capabilities, etc.).
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array;
}
