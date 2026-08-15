<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Driver;

use CleatSquad\LlmRouter\Driver\GroqAudioDriver;
use CleatSquad\LlmRouter\DTO\AudioTranscriptionRequest;
use CleatSquad\LlmRouter\Enum\DriverType;
use CleatSquad\LlmRouter\Http\HttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
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

    public function testTranscribeThrowsRateLimitExceptionWithRetryAfterHeader(): void
    {
        $driver = $this->driverWithMockedResponses([
            new Response(429, ['Retry-After' => '120'], 'Rate limit reached'),
        ]);

        try {
            $driver->transcribe(new AudioTranscriptionRequest('fake-audio-bytes'));
            $this->fail('Expected RateLimitException');
        } catch (\CleatSquad\LlmRouter\Exception\RateLimitException $e) {
            $this->assertSame(120, $e->getRetryAfterSeconds());
            $this->assertSame(429, $e->getStatusCode());
        }
    }
}
