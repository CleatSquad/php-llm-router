<?php

declare(strict_types=1);

namespace LlmRouter\Tests\Driver;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LlmRouter\Driver\GroqAudioDriver;
use LlmRouter\DTO\AudioTranscriptionRequest;
use LlmRouter\Enum\DriverType;
use LlmRouter\Http\HttpClient;
use PHPUnit\Framework\TestCase;

final class GroqAudioDriverTest extends TestCase
{
    /**
     * @param Response[] $responses
     */
    private function driverWithMockedResponses(array $responses): GroqAudioDriver
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $client = new Client(['handler' => $handlerStack]);

        return new GroqAudioDriver(new HttpClient($client), groqApiKey: 'test-key');
    }

    public function testGetTypeIsAudio(): void
    {
        $this->assertSame(DriverType::AUDIO, $this->driverWithMockedResponses([])->getType());
    }

    public function testTranscribeExtractsTextAndDuration(): void
    {
        $driver = $this->driverWithMockedResponses([
            new Response(200, [], json_encode([
                'text' => 'salut ça va',
                'language' => 'french',
                'duration' => 1.8,
            ], JSON_THROW_ON_ERROR)),
        ]);

        $response = $driver->transcribe(new AudioTranscriptionRequest('fake-audio-bytes'));

        $this->assertSame('salut ça va', $response->text);
        $this->assertSame('whisper-large-v3', $response->model);
        $this->assertSame(1.8, $response->durationSeconds);
        $this->assertGreaterThan(0.0, $response->costUsd);
    }

    public function testTranscribeThrowsOnApiError(): void
    {
        $driver = $this->driverWithMockedResponses([
            new Response(200, [], json_encode([
                'error' => ['message' => 'invalid api key'],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid api key');
        $driver->transcribe(new AudioTranscriptionRequest('fake-audio-bytes'));
    }
}
