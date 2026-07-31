<?php

declare(strict_types=1);

namespace LlmRouter\Tests\Driver;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LlmRouter\Driver\OpenAiAudioDriver;
use LlmRouter\DTO\AudioTranscriptionRequest;
use LlmRouter\Enum\DriverType;
use LlmRouter\Http\HttpClient;
use PHPUnit\Framework\TestCase;

final class OpenAiAudioDriverTest extends TestCase
{
    /**
     * @param Response[] $responses
     */
    private function driverWithMockedResponses(array $responses, array &$history = []): OpenAiAudioDriver
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($history));
        $client = new Client(['handler' => $handlerStack]);

        return new OpenAiAudioDriver(new HttpClient($client), openAiApiKey: 'test-key');
    }

    public function testGetTypeIsAudio(): void
    {
        $this->assertSame(DriverType::AUDIO, $this->driverWithMockedResponses([])->getType());
    }

    public function testTranscribeExtractsTextDurationAndCost(): void
    {
        $driver = $this->driverWithMockedResponses([
            new Response(200, [], json_encode([
                'text' => 'Bonjour, ceci est un test.',
                'language' => 'french',
                'duration' => 3.2,
            ], JSON_THROW_ON_ERROR)),
        ]);

        $response = $driver->transcribe(AudioTranscriptionRequest::fromFile(__FILE__));

        $this->assertSame('Bonjour, ceci est un test.', $response->text);
        $this->assertSame('whisper-1', $response->model);
        $this->assertSame('french', $response->language);
        $this->assertSame(3.2, $response->durationSeconds);
        $this->assertGreaterThan(0.0, $response->costUsd);
    }

    public function testTranscribeExtractsConfidenceFromVerboseJsonSegments(): void
    {
        $driver = $this->driverWithMockedResponses([
            new Response(200, [], json_encode([
                'text' => 'insurtement',
                'duration' => 1.1,
                'segments' => [
                    ['avg_logprob' => -1.4, 'no_speech_prob' => 0.7],
                    ['avg_logprob' => -1.8, 'no_speech_prob' => 0.9],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $response = $driver->transcribe(AudioTranscriptionRequest::fromFile(__FILE__));

        $this->assertSame(-1.6, $response->avgLogprob);
        $this->assertSame(0.8, $response->noSpeechProb);
        $this->assertTrue($response->isLowConfidence());
    }

    public function testTranscribeWithoutSegmentsLeavesConfidenceNull(): void
    {
        $driver = $this->driverWithMockedResponses([
            new Response(200, [], json_encode([
                'text' => 'Bonjour',
                'duration' => 1.0,
            ], JSON_THROW_ON_ERROR)),
        ]);

        $response = $driver->transcribe(AudioTranscriptionRequest::fromFile(__FILE__));

        $this->assertNull($response->avgLogprob);
        $this->assertNull($response->noSpeechProb);
        $this->assertFalse($response->isLowConfidence());
    }

    public function testTranscribeSendsAMultipartRequestWithFileModelAndLanguage(): void
    {
        $history = [];
        $driver = $this->driverWithMockedResponses([
            new Response(200, [], json_encode(['text' => 'ok', 'duration' => 1.0], JSON_THROW_ON_ERROR)),
        ], $history);

        $driver->transcribe(new AudioTranscriptionRequest('fake-audio-bytes', 'note.ogg', 'whisper-1', 'fr'));

        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertStringContainsString('multipart/form-data', $request->getHeaderLine('Content-Type'));
        $body = (string) $request->getBody();
        $this->assertStringContainsString('fake-audio-bytes', $body);
        $this->assertStringContainsString('note.ogg', $body);
        $this->assertStringContainsString('fr', $body);
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

    public function testIsAvailableReflectsApiKey(): void
    {
        $this->assertTrue($this->driverWithMockedResponses([])->isAvailable());
        $this->assertFalse((new OpenAiAudioDriver(new HttpClient(new Client())))->isAvailable());
    }

    public function testEstimateCostIsRoughlyProportionalToAudioSize(): void
    {
        $driver = $this->driverWithMockedResponses([]);

        $small = $driver->estimateCost(new AudioTranscriptionRequest(str_repeat('a', 3000)));
        $large = $driver->estimateCost(new AudioTranscriptionRequest(str_repeat('a', 30000)));

        $this->assertGreaterThan($small->estimatedCostUsd, $large->estimatedCostUsd);
    }
}
