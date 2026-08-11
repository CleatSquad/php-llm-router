<?php

declare(strict_types=1);

namespace LlmRouter\Tests\Driver;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LlmRouter\Contract\Driver\LLMDriverInterface;
use LlmRouter\Driver\ClaudeDriver;
use LlmRouter\Driver\DeepSeekDriver;
use LlmRouter\Driver\GeminiDriver;
use LlmRouter\Driver\GroqDriver;
use LlmRouter\Driver\MistralDriver;
use LlmRouter\Driver\OpenAiDriver;
use LlmRouter\DTO\LLMRequest;
use LlmRouter\Exception\UnknownModelException;
use LlmRouter\Http\HttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Invariant protected: a model the caller explicitly asked for is either used
 *   or refused — never silently swapped for the driver's default.
 * Bug covered: `isset(self::PRICING[$model]) ? $model : 'gpt-4o-mini'`, repeated
 *   in six drivers. Asking OpenAiDriver for "gpt-5" returned an answer from
 *   gpt-4o-mini, priced as gpt-4o-mini, with nothing in the response saying so.
 *   No test covered this, so it was accidental behaviour rather than contract.
 * Type: regression + security (billing correctness).
 */
final class ModelResolutionTest extends TestCase
{
    private function http(): HttpClient
    {
        // Any call reaching the network would be a test bug: resolution is
        // expected to fail before a request is ever sent.
        return new HttpClient(new Client([
            'handler' => HandlerStack::create(new MockHandler([new Response(200, [], '{}')])),
        ]));
    }

    private function request(?string $model): LLMRequest
    {
        return new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']], model: $model);
    }

    /**
     * @return array<string, array{0: class-string<LLMDriverInterface>, 1: string, 2: string}>
     */
    public static function pricedDrivers(): array
    {
        return [
            'Claude' => [ClaudeDriver::class, 'claude-sonnet-5', 'claude-does-not-exist'],
            'OpenAI' => [OpenAiDriver::class, 'gpt-4o-mini', 'gpt-5'],
            'Gemini' => [GeminiDriver::class, 'gemini-2.5-flash-lite', 'gemini-9-ultra'],
            'DeepSeek' => [DeepSeekDriver::class, 'deepseek-v4-flash', 'deepseek-reasoner-v9'],
            'Groq' => [GroqDriver::class, 'llama-3.1-8b-instant', 'llama-99b'],
            'Mistral' => [MistralDriver::class, 'mistral-small-latest', 'mistral-enormous'],
        ];
    }

    /**
     * @param class-string<LLMDriverInterface> $driverClass
     */
    #[DataProvider('pricedDrivers')]
    public function testAnUnknownModelIsRefusedInsteadOfSubstituted(
        string $driverClass,
        string $knownModel,
        string $unknownModel,
    ): void {
        $driver = new $driverClass($this->http());

        try {
            $driver->chat($this->request($unknownModel));
            $this->fail("$driverClass silently accepted '$unknownModel'");
        } catch (UnknownModelException $e) {
            $this->assertSame($unknownModel, $e->requestedModel);
            $this->assertContains($knownModel, $e->knownModels);
            // The message has to name the alternatives, or the caller is left
            // guessing what this build actually supports.
            $this->assertStringContainsString($unknownModel, $e->getMessage());
            $this->assertStringContainsString($knownModel, $e->getMessage());
        }
    }

    /**
     * @param class-string<LLMDriverInterface> $driverClass
     */
    #[DataProvider('pricedDrivers')]
    public function testEstimateCostAlsoRefusesRatherThanQuotingTheWrongModel(
        string $driverClass,
        string $knownModel,
        string $unknownModel,
    ): void {
        $driver = new $driverClass($this->http());

        // Quoting the default model's price for a model you didn't ask for is
        // the same defect wearing a different hat: a number that looks right.
        $this->expectException(UnknownModelException::class);
        $driver->estimateCost($this->request($unknownModel));
    }

    /**
     * @param class-string<LLMDriverInterface> $driverClass
     */
    #[DataProvider('pricedDrivers')]
    public function testNoModelAtAllStillResolvesToTheDriverDefault(
        string $driverClass,
        string $knownModel,
        string $unknownModel,
    ): void {
        $driver = new $driverClass($this->http());

        // Passing no model is a caller declining to choose, not a caller being
        // overruled — it must keep working exactly as before.
        $this->assertGreaterThanOrEqual(0.0, $driver->estimateCost($this->request(null))->estimatedCostUsd);
    }

    /**
     * @param class-string<LLMDriverInterface> $driverClass
     */
    #[DataProvider('pricedDrivers')]
    public function testAKnownModelIsHonouredAndPricedAsItself(
        string $driverClass,
        string $knownModel,
        string $unknownModel,
    ): void {
        $driver = new $driverClass($this->http());

        $this->assertContains($knownModel, $driver->getModels());
        $this->assertGreaterThanOrEqual(0.0, $driver->estimateCost($this->request($knownModel))->estimatedCostUsd);
    }

    public function testAProviderPrefixedModelStillResolves(): void
    {
        $driver = new ClaudeDriver($this->http());

        // "anthropic/claude-sonnet-5" is the same model; proxied setups carry
        // the prefix and must not start failing.
        $this->assertGreaterThanOrEqual(
            0.0,
            $driver->estimateCost($this->request('anthropic/claude-sonnet-5'))->estimatedCostUsd
        );
    }

    public function testAModelIdThatLegitimatelyContainsASlashIsNotTruncated(): void
    {
        // Groq serves "openai/gpt-oss-120b" — the slash is part of the name,
        // not a provider prefix. Stripping eagerly turned a known model into
        // an unknown one and raised UnknownModelException.
        $driver = new \LlmRouter\Driver\GroqDriver($this->http());

        $this->assertContains('openai/gpt-oss-120b', $driver->getModels());
        $this->assertGreaterThan(
            0.0,
            $driver->estimateCost($this->request('openai/gpt-oss-120b'))->estimatedCostUsd
        );
    }

    public function testAProviderPrefixIsStillStrippedWhenTheFullNameIsUnknown(): void
    {
        $driver = new \LlmRouter\Driver\GroqDriver($this->http());

        // "groq/llama-3.1-8b-instant" isn't a catalogue entry, but the part
        // after the prefix is — proxied setups depend on this.
        $this->assertGreaterThan(
            0.0,
            $driver->estimateCost($this->request('groq/llama-3.1-8b-instant'))->estimatedCostUsd
        );
    }

    public function testExtraPricingRegistersAModelThisReleasePredates(): void
    {
        $driver = new OpenAiDriver($this->http(), extraModelPricing: [
            'gpt-5' => ['input' => 0.00125, 'output' => 0.01],
        ]);

        $this->assertContains('gpt-5', $driver->getModels());

        $cost = $driver->estimateCost($this->request('gpt-5'));
        $this->assertGreaterThan(0.0, $cost->estimatedCostUsd);
    }

    public function testExtraPricingCanCorrectAStalePriceWithoutEditingThePackage(): void
    {
        $shipped = (new OpenAiDriver($this->http()))->estimateCost($this->request('gpt-4o-mini'));
        $corrected = (new OpenAiDriver($this->http(), extraModelPricing: [
            'gpt-4o-mini' => ['input' => 1.0, 'output' => 1.0],
        ]))->estimateCost($this->request('gpt-4o-mini'));

        $this->assertGreaterThan($shipped->estimatedCostUsd, $corrected->estimatedCostUsd);
    }

    public function testTheExceptionIsARuntimeExceptionSoFailoverCanStepIn(): void
    {
        // In a mixed chain, the driver that doesn't know the requested model is
        // exactly the one that should stand aside for the one that does — which
        // only happens if FailoverDriver sees a RuntimeException.
        $this->assertTrue(is_subclass_of(UnknownModelException::class, RuntimeException::class));
    }

    public function testKimiStillPassesUnknownModelsStraightThrough(): void
    {
        // Kimi has no pricing table to validate against and deliberately
        // forwards whatever it is given; this pins that it was left alone.
        $driver = new \LlmRouter\Driver\KimiDriver($this->http());

        $this->assertGreaterThanOrEqual(0.0, $driver->estimateCost($this->request('kimi-k99'))->estimatedCostUsd);
    }
}
