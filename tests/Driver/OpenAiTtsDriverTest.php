<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Driver;

use CleatSquad\LlmRouter\Driver\OpenAiTtsDriver;
use CleatSquad\LlmRouter\DTO\SpeechSynthesisRequest;
use CleatSquad\LlmRouter\Enum\DriverType;
use CleatSquad\LlmRouter\Http\HttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class OpenAiTtsDriverTest extends TestCase
{
    /**
     * @param Response[] $responses
     */
    private function driverWithMockedResponses(array $responses, array &$history = []): OpenAiTtsDriver
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($history));
        $client = new Client(['handler' => $handlerStack]);

        return new OpenAiTtsDriver(new HttpClient($client), openAiApiKey: 'test-key');
    }

    public function testGetTypeIsAudio(): void
    {
        $this->assertSame(DriverType::AUDIO, $this->driverWithMockedResponses([])->getType());
    }

    public function testSynthesizeReturnsAudioBytesAndCost(): void
    {
        $driver = $this->driverWithMockedResponses([
            new Response(200, [], 'fake-mp3-bytes'),
        ]);

        $response = $driver->synthesize(new SpeechSynthesisRequest('Bonjour, ceci est un test.'));

        $this->assertSame('fake-mp3-bytes', $response->audioContent);
        $this->assertSame('mp3', $response->format);
        $this->assertSame('tts-1', $response->model);
        $this->assertGreaterThan(0.0, $response->costUsd);
    }

    public function testSynthesizeSendsJsonRequestWithTextVoiceAndFormat(): void
    {
        $history = [];
        $driver = $this->driverWithMockedResponses([
            new Response(200, [], 'audio-bytes'),
        ], $history);

        $driver->synthesize(new SpeechSynthesisRequest('Bonjour', voice: 'nova', format: 'opus'));

        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertStringContainsString('application/json', $request->getHeaderLine('Content-Type'));
        $decoded = json_decode((string) $request->getBody(), true);
        $this->assertSame('Bonjour', $decoded['input']);
        $this->assertSame('nova', $decoded['voice']);
        $this->assertSame('opus', $decoded['response_format']);
    }

    public function testSynthesizeThrowsOnApiError(): void
    {
        $driver = $this->driverWithMockedResponses([
            new Response(400, [], json_encode(['error' => ['message' => 'invalid api key']], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid api key');
        $driver->synthesize(new SpeechSynthesisRequest('Bonjour'));
    }

    public function testIsAvailableReflectsApiKey(): void
    {
        $this->assertTrue($this->driverWithMockedResponses([])->isAvailable());
        $this->assertFalse((new OpenAiTtsDriver(new HttpClient(new Client())))->isAvailable());
    }

    public function testEstimateCostIsProportionalToTextLength(): void
    {
        $driver = $this->driverWithMockedResponses([]);

        $short = $driver->estimateCost(new SpeechSynthesisRequest(str_repeat('a', 100)));
        $long = $driver->estimateCost(new SpeechSynthesisRequest(str_repeat('a', 2000)));

        $this->assertGreaterThan($short->estimatedCostUsd, $long->estimatedCostUsd);
    }
}
