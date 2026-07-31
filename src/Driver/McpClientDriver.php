<?php

declare(strict_types=1);

namespace LlmRouter\Driver;

use GuzzleHttp\Client as GuzzleClient;
use InvalidArgumentException;
use LlmRouter\Contract\Driver\MCPDriverInterface;
use LlmRouter\DTO\HealthStatus;
use LlmRouter\DTO\ToolResult;
use LlmRouter\Enum\DriverType;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Client\Transport\TransportInterface;
use Mcp\Schema\Content\TextContent;
use Throwable;

/**
 * MCP client driver backed by the official mcp/sdk PHP client. One
 * instance per configured MCP server: the underlying SDK client connects
 * using whichever transport (stdio process or HTTP) the config describes.
 */
class McpClientDriver implements MCPDriverInterface
{
    private readonly Client $client;
    private bool $connected = false;

    /**
     * @param array{
     *     id?: string,
     *     name?: string,
     *     transport?: string,
     *     command?: string|null,
     *     args?: array<int, string>|null,
     *     env?: array<string, string>|null,
     *     url?: string|null,
     *     headers?: array<string, string>
     * } $config `headers` (e.g. a Bearer Authorization header) is for OAuth-protected HTTP servers.
     */
    public function __construct(private readonly array $config)
    {
        $this->client = Client::builder()
            ->setClientInfo('php-llm-router', '1.0.0')
            ->setInitTimeout(15)
            ->setRequestTimeout(60)
            ->build();
    }

    public function getId(): string
    {
        return (string) ($this->config['id'] ?? 'mcp-client');
    }

    public function getName(): string
    {
        return (string) ($this->config['name'] ?? $this->getId());
    }

    public function getType(): DriverType
    {
        return DriverType::MCP;
    }

    public function isAvailable(): bool
    {
        return match ($this->config['transport'] ?? null) {
            'stdio' => !empty($this->config['command']),
            'http' => !empty($this->config['url']),
            default => false,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return [
            'transport' => $this->config['transport'] ?? null,
            'connected' => $this->connected,
        ];
    }

    public function connect(): void
    {
        $this->client->connect($this->buildTransport());
        $this->connected = true;
    }

    public function disconnect(): void
    {
        if ($this->connected) {
            $this->client->disconnect();
            $this->connected = false;
        }
    }

    public function healthCheck(): HealthStatus
    {
        $start = microtime(true);
        try {
            $this->connect();
            $toolCount = count($this->client->listTools()->tools);
            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            return HealthStatus::healthy($latencyMs, sprintf('%d tool(s) available', $toolCount));
        } catch (Throwable $e) {
            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            return HealthStatus::unhealthy($e->getMessage(), $latencyMs);
        } finally {
            $this->disconnect();
        }
    }

    public function listTools(): array
    {
        return array_map(
            static fn ($tool) => [
                'name' => $tool->name,
                'description' => $tool->description ?? '',
                'inputSchema' => $tool->inputSchema,
            ],
            $this->client->listTools()->tools
        );
    }

    public function listPrompts(): array
    {
        return array_map(
            static fn ($prompt) => [
                'name' => $prompt->name,
                'description' => $prompt->description ?? '',
                'arguments' => array_map(
                    static fn ($arg) => [
                        'name' => $arg->name,
                        'description' => $arg->description,
                        'required' => (bool) $arg->required,
                    ],
                    $prompt->arguments ?? []
                ),
            ],
            $this->client->listPrompts()->prompts
        );
    }

    public function listResources(): array
    {
        return array_map(
            static fn ($resource) => [
                'uri' => $resource->uri,
                'name' => $resource->name,
                'mimeType' => $resource->mimeType ?? '',
            ],
            $this->client->listResources()->resources
        );
    }

    public function callTool(string $name, array $args = []): ToolResult
    {
        try {
            $result = $this->client->callTool($name, $args);
        } catch (Throwable $e) {
            return new ToolResult(success: false, output: '', error: $e->getMessage());
        }

        $textParts = [];
        foreach ($result->content as $item) {
            if ($item instanceof TextContent) {
                $textParts[] = (string) $item->text;
            }
        }
        $output = implode("\n", $textParts);

        return new ToolResult(
            success: !$result->isError,
            output: $output,
            error: $result->isError ? $output : null,
        );
    }

    private function buildTransport(): TransportInterface
    {
        return match ($this->config['transport'] ?? null) {
            'stdio' => new StdioTransport(
                command: (string) ($this->config['command'] ?? ''),
                args: $this->config['args'] ?? [],
                env: $this->config['env'] ?? null,
            ),
            // A hanging MCP server otherwise blocks indefinitely at the socket
            // level: the SDK's own setRequestTimeout(60) above is only a
            // logical/Fiber-side bookkeeping timeout, not a real network
            // timeout on the auto-discovered PSR-18 client.
            'http' => new HttpTransport(
                (string) ($this->config['url'] ?? ''),
                $this->config['headers'] ?? [],
                new GuzzleClient(['timeout' => 20.0, 'connect_timeout' => 10.0]),
            ),
            default => throw new InvalidArgumentException(sprintf(
                'Unsupported MCP transport "%s"',
                (string) ($this->config['transport'] ?? 'null')
            )),
        };
    }
}
