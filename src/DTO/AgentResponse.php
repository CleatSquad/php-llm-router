<?php

declare(strict_types=1);

namespace LlmRouter\DTO;

/**
 * Result of an agent execution (A2A task, coding-agent CLI run, etc.).
 */
final readonly class AgentResponse
{
    /**
     * @param array<string, mixed> $metadata Protocol-specific extras (taskId, contextId, state, raw payload, ...)
     */
    public function __construct(
        public bool $success,
        public string $output,
        public int $durationMs,
        public array $metadata = [],
    ) {}
}
