<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\DTO;

/**
 * Result of invoking a single tool (MCP tool call, function call, etc.).
 */
final readonly class ToolResult
{
    public function __construct(
        public bool $success,
        public string $output,
        public ?string $error = null,
    ) {}
}
