<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Contract\Driver;

use CleatSquad\LlmRouter\DTO\AgentResponse;

interface AgentDriverInterface extends DriverInterface
{
    /**
     * Execute a specific instruction within a given context.
     *
     * @param array<string, mixed> $context Contextual data (session, task/conversation ids, etc.)
     */
    public function execute(string $instruction, array $context = []): AgentResponse;

    /**
     * Get the list of capabilities/skills supported by the agent.
     *
     * @return string[]
     */
    public function getCapabilities(): array;

    /**
     * Determine if the agent supports streaming output.
     */
    public function supportsStreaming(): bool;
}
