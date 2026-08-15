<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Contract\Driver;

use CleatSquad\LlmRouter\DTO\ToolResult;

interface MCPDriverInterface extends DriverInterface
{
    /**
     * Establish connection with the MCP server.
     */
    public function connect(): void;

    /**
     * Terminate connection with the MCP server.
     */
    public function disconnect(): void;

    /**
     * List all available tools on the MCP server.
     *
     * @return array<int, array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function listTools(): array;

    /**
     * List all available prompts on the MCP server.
     *
     * @return array<int, array{name: string, description: string, arguments: array<int, array{name: string, description: string|null, required: bool}>}>
     */
    public function listPrompts(): array;

    /**
     * List all available resources on the MCP server.
     *
     * @return array<int, array{uri: string, name: string, mimeType: string}>
     */
    public function listResources(): array;

    /**
     * Call a specific tool on the MCP server.
     *
     * @param array<string, mixed> $args
     */
    public function callTool(string $name, array $args = []): ToolResult;
}
