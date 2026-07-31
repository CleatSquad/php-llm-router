<?php

declare(strict_types=1);

namespace LlmRouter\Tests\Driver;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LlmRouter\Driver\A2AClientDriver;
use LlmRouter\DTO\HealthState;
use LlmRouter\Enum\DriverType;
use LlmRouter\Http\HttpClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class A2AClientDriverTest extends TestCase
{
    private const AGENT_CARD = [
        'name' => 'Test Agent',
        'description' => 'A test agent',
        'url' => 'https://agent.example.com/rpc',
        'version' => '1.0.0',
        'protocolVersion' => '0.3.0',
        'capabilities' => ['streaming' => true],
        'skills' => [
            ['id' => 'echo', 'name' => 'Echo', 'description' => 'Echoes text', 'tags' => []],
        ],
        'defaultInputModes' => ['text'],
        'defaultOutputModes' => ['text'],
    ];

    /**
     * @param Response[] $responses
     */
    private function driverWithMockedResponses(array $responses): A2AClientDriver
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $client = new Client(['handler' => $handlerStack]);

        return new A2AClientDriver(new HttpClient($client), 'https://agent.example.com');
    }

    private function agentCardResponse(): Response
    {
        return new Response(200, [], json_encode(self::AGENT_CARD, JSON_THROW_ON_ERROR));
    }

    public function testGetAgentCardParsesTheWellKnownDocument(): void
    {
        $driver = $this->driverWithMockedResponses([$this->agentCardResponse()]);

        $card = $driver->getAgentCard();

        $this->assertSame('Test Agent', $card->name);
        $this->assertSame('https://agent.example.com/rpc', $card->url);
        $this->assertTrue($card->supportsStreaming());
        $this->assertSame(['echo'], $driver->getCapabilities());
    }

    public function testGetTypeIsAgent(): void
    {
        $this->assertSame(DriverType::AGENT, $this->driverWithMockedResponses([])->getType());
    }

    public function testExecuteSendsMessageAndParsesACompletedTask(): void
    {
        $driver = $this->driverWithMockedResponses([
            $this->agentCardResponse(),
            new Response(200, [], json_encode([
                'jsonrpc' => '2.0',
                'id' => '1',
                'result' => [
                    'kind' => 'task',
                    'id' => 'task-1',
                    'contextId' => 'ctx-1',
                    'status' => [
                        'state' => 'completed',
                        'message' => ['role' => 'agent', 'parts' => [['kind' => 'text', 'text' => '42']]],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $response = $driver->execute('what is 6*7?');

        $this->assertTrue($response->success);
        $this->assertSame('42', $response->output);
        $this->assertSame('task-1', $response->metadata['taskId']);
        $this->assertSame('ctx-1', $response->metadata['contextId']);
        $this->assertSame('completed', $response->metadata['state']);
    }

    public function testExecuteHandlesADirectMessageResultWithNoTask(): void
    {
        $driver = $this->driverWithMockedResponses([
            $this->agentCardResponse(),
            new Response(200, [], json_encode([
                'jsonrpc' => '2.0',
                'id' => '1',
                'result' => [
                    'kind' => 'message',
                    'role' => 'agent',
                    'parts' => [['kind' => 'text', 'text' => 'hi there']],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $response = $driver->execute('hello');

        $this->assertTrue($response->success);
        $this->assertSame('hi there', $response->output);
    }

    public function testExecuteThrowsOnJsonRpcError(): void
    {
        $driver = $this->driverWithMockedResponses([
            $this->agentCardResponse(),
            new Response(200, [], json_encode([
                'jsonrpc' => '2.0',
                'id' => '1',
                'error' => ['code' => -32000, 'message' => 'boom'],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $driver->execute('hello');
    }

    public function testGetTaskAndCancelTaskParseTaskState(): void
    {
        $driver = $this->driverWithMockedResponses([
            $this->agentCardResponse(),
            new Response(200, [], json_encode([
                'jsonrpc' => '2.0',
                'id' => '1',
                'result' => ['kind' => 'task', 'id' => 'task-1', 'status' => ['state' => 'working']],
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode([
                'jsonrpc' => '2.0',
                'id' => '2',
                'result' => ['kind' => 'task', 'id' => 'task-1', 'status' => ['state' => 'canceled']],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $working = $driver->getTask('task-1');
        $this->assertFalse($working->success);
        $this->assertSame('working', $working->metadata['state']);

        $canceled = $driver->cancelTask('task-1');
        $this->assertFalse($canceled->success);
        $this->assertSame('canceled', $canceled->metadata['state']);
    }

    public function testStreamYieldsTextFragmentsAndReturnsFinalAgentResponse(): void
    {
        $sse = '';
        foreach ([
            ['kind' => 'status-update', 'taskId' => 't1', 'contextId' => 'c1', 'status' => ['state' => 'working']],
            ['kind' => 'artifact-update', 'taskId' => 't1', 'contextId' => 'c1', 'artifact' => ['parts' => [['kind' => 'text', 'text' => 'Hello ']]]],
            ['kind' => 'artifact-update', 'taskId' => 't1', 'contextId' => 'c1', 'artifact' => ['parts' => [['kind' => 'text', 'text' => 'world']]]],
            ['kind' => 'status-update', 'taskId' => 't1', 'contextId' => 'c1', 'status' => ['state' => 'completed'], 'final' => true],
        ] as $event) {
            $sse .= 'data: ' . json_encode(['jsonrpc' => '2.0', 'id' => '1', 'result' => $event], JSON_THROW_ON_ERROR) . "\n\n";
        }

        $driver = $this->driverWithMockedResponses([
            $this->agentCardResponse(),
            new Response(200, [], $sse),
        ]);

        $chunks = [];
        $generator = $driver->stream('hello');
        foreach ($generator as $chunk) {
            $chunks[] = $chunk;
        }
        $result = $generator->getReturn();

        $this->assertSame(['Hello ', 'world'], $chunks);
        $this->assertTrue($result->success);
        $this->assertSame('Hello world', $result->output);
        $this->assertSame('completed', $result->metadata['state']);
        $this->assertSame('t1', $result->metadata['taskId']);
    }

    public function testHealthCheckReflectsAgentReachability(): void
    {
        $healthy = $this->driverWithMockedResponses([$this->agentCardResponse()])->healthCheck();
        $this->assertSame(HealthState::HEALTHY, $healthy->status);

        $unhealthy = $this->driverWithMockedResponses([new Response(500, [], 'nope')])->healthCheck();
        $this->assertSame(HealthState::UNHEALTHY, $unhealthy->status);
    }
}
