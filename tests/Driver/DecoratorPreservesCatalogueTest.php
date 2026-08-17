<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Driver;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\Contract\Driver\ModelCatalogueInterface;
use CleatSquad\LlmRouter\Driver\CachingDriver;
use CleatSquad\LlmRouter\Driver\CircuitBreakerDriver;
use CleatSquad\LlmRouter\Driver\RateLimitedDriver;
use CleatSquad\LlmRouter\Driver\RetryingDriver;
use CleatSquad\LlmRouter\Tests\Fixtures\FakeDriver;
use PHPUnit\Framework\TestCase;

/**
 * A decorator must preserve the contract of what it decorates. A caller asking
 * "which driver serves this model?" only ever holds the outermost wrapper, so a
 * layer that drops the catalogue makes every driver look unable to serve
 * anything — and the request silently falls through to whatever the caller does
 * when nobody answers.
 */
final class DecoratorPreservesCatalogueTest extends TestCase
{
    /**
     * @return list<array{string, callable(LLMDriverInterface): LLMDriverInterface}>
     */
    public static function decorators(): array
    {
        return [
            'circuit breaker' => ['CircuitBreakerDriver', static fn (LLMDriverInterface $d) => new CircuitBreakerDriver($d)],
            'retrying' => ['RetryingDriver', static fn (LLMDriverInterface $d) => new RetryingDriver($d)],
            'rate limited' => ['RateLimitedDriver', static fn (LLMDriverInterface $d) => new RateLimitedDriver($d)],
            'caching' => ['CachingDriver', static fn (LLMDriverInterface $d) => new CachingDriver($d)],
        ];
    }

    /**
     * @dataProvider decorators
     * @param callable(LLMDriverInterface): LLMDriverInterface $wrap
     */
    public function testDecoratorStillAnswersTheCatalogueQuestion(string $name, callable $wrap): void
    {
        $decorated = $wrap(self::cataloguedDriver());

        $this->assertInstanceOf(
            ModelCatalogueInterface::class,
            $decorated,
            $name . ' hides the catalogue of the driver it decorates, so no caller can find who serves a model.'
        );
        $this->assertTrue($decorated->supportsModel('served-model'));
        $this->assertFalse($decorated->supportsModel('foreign-model'));
    }

    /**
     * A driver that answers no catalogue question keeps the documented default:
     * it is assumed able to serve what it is given.
     *
     * @dataProvider decorators
     * @param callable(LLMDriverInterface): LLMDriverInterface $wrap
     */
    public function testDecoratorOverAPlainDriverAssumesItServes(string $name, callable $wrap): void
    {
        $decorated = $wrap(self::plainDriver());

        $this->assertInstanceOf(ModelCatalogueInterface::class, $decorated, $name);
        $this->assertTrue($decorated->supportsModel('anything'), $name . ' must not narrow a plain driver.');
    }

    private static function cataloguedDriver(): LLMDriverInterface
    {
        return new class(new FakeDriver('mock')) implements LLMDriverInterface, ModelCatalogueInterface {
            public function __construct(private readonly LLMDriverInterface $inner) {}
            public function getId(): string { return $this->inner->getId(); }
            public function getName(): string { return $this->inner->getName(); }
            public function getType(): \CleatSquad\LlmRouter\Enum\DriverType { return $this->inner->getType(); }
            public function isAvailable(): bool { return $this->inner->isAvailable(); }
            public function healthCheck(): \CleatSquad\LlmRouter\DTO\HealthStatus { return $this->inner->healthCheck(); }
            public function getMetadata(): array { return $this->inner->getMetadata(); }
            public function chat(\CleatSquad\LlmRouter\DTO\LLMRequest $request): \CleatSquad\LlmRouter\DTO\LLMResponse { return $this->inner->chat($request); }
            public function stream(\CleatSquad\LlmRouter\DTO\LLMRequest $request): \Generator { return yield from $this->inner->stream($request); }
            public function getModels(): array { return $this->inner->getModels(); }
            public function supportsStreaming(): bool { return $this->inner->supportsStreaming(); }
            public function supportsTools(): bool { return $this->inner->supportsTools(); }
            public function supportsVision(): bool { return $this->inner->supportsVision(); }
            public function supportsReasoning(): bool { return $this->inner->supportsReasoning(); }
            public function estimateCost(\CleatSquad\LlmRouter\DTO\LLMRequest $request): \CleatSquad\LlmRouter\DTO\CostEstimate { return $this->inner->estimateCost($request); }
            public function supportsModel(string $model): bool
            {
                return $model === 'served-model';
            }
        };
    }

    private static function plainDriver(): LLMDriverInterface
    {
        return new FakeDriver('plain');
    }
}
