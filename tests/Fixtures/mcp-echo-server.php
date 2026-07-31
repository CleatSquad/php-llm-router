<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

// Minimal stdio MCP server used by McpClientDriverTest to exercise the
// real mcp/sdk wire protocol end to end, instead of mocking it.
$server = Server::builder()
    ->setServerInfo('echo-fixture', '1.0.0')
    ->addTool(
        static fn (string $text): string => $text,
        name: 'echo',
        description: 'Echoes back the given text.',
        inputSchema: [
            'type' => 'object',
            'properties' => ['text' => ['type' => 'string']],
            'required' => ['text'],
        ],
    )
    ->build();

$server->run(new StdioTransport());
