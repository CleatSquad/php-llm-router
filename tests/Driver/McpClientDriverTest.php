<?php

declare(strict_types=1);

namespace LlmRouter\Tests\Driver;

use LlmRouter\Driver\McpClientDriver;
use LlmRouter\DTO\HealthState;
use LlmRouter\Enum\DriverType;
use PHPUnit\Framework\TestCase;

final class McpClientDriverTest extends TestCase
{
    private function driver(): McpClientDriver
    {
        return new McpClientDriver([
            'id' => 'echo-fixture',
            'name' => 'Echo Fixture',
            'transport' => 'stdio',
            'command' => 'php',
            'args' => [__DIR__ . '/../Fixtures/mcp-echo-server.php'],
        ]);
    }

    public function testGetTypeIsMcp(): void
    {
        $this->assertSame(DriverType::MCP, $this->driver()->getType());
    }

    public function testIsAvailableReflectsTransportConfig(): void
    {
        $this->assertTrue($this->driver()->isAvailable());
        $this->assertFalse((new McpClientDriver(['transport' => 'stdio']))->isAvailable());
        $this->assertFalse((new McpClientDriver([]))->isAvailable());
    }

    public function testListToolsAndCallToolAgainstRealStdioServer(): void
    {
        $driver = $this->driver();
        $driver->connect();

        try {
            $tools = $driver->listTools();
            $this->assertCount(1, $tools);
            $this->assertSame('echo', $tools[0]['name']);

            $result = $driver->callTool('echo', ['text' => 'hello mcp']);
            $this->assertTrue($result->success);
            $this->assertSame('hello mcp', $result->output);
            $this->assertNull($result->error);
        } finally {
            $driver->disconnect();
        }
    }

    public function testCallToolReturnsFailureResultForUnknownTool(): void
    {
        $driver = $this->driver();
        $driver->connect();

        try {
            $result = $driver->callTool('does-not-exist');
            $this->assertFalse($result->success);
            $this->assertNotNull($result->error);
        } finally {
            $driver->disconnect();
        }
    }

    public function testHealthCheckConnectsAndReportsToolCount(): void
    {
        $status = $this->driver()->healthCheck();

        $this->assertSame(HealthState::HEALTHY, $status->status);
        $this->assertSame('1 tool(s) available', $status->message);
    }

    public function testUnsupportedTransportThrowsOnConnect(): void
    {
        $driver = new McpClientDriver(['transport' => 'carrier-pigeon']);

        $this->expectException(\InvalidArgumentException::class);
        $driver->connect();
    }
}
