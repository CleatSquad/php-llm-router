<?php

declare(strict_types=1);

namespace LlmRouter\Tests\CircuitBreaker;

use DateTimeImmutable;
use LlmRouter\CircuitBreaker\CircuitBreakerState;
use LlmRouter\CircuitBreaker\Psr16CircuitBreakerStore;
use LlmRouter\Tests\Fixtures\ArrayPsr16Cache;
use LlmRouter\Tests\Fixtures\GadgetProbe;
use LlmRouter\Tests\Fixtures\RecordingLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Invariant protected: sharing breaker state without Redis behaves exactly
 *   like the Redis store — JSON only, fail-open, no instantiation from payload.
 * Type: feature + security.
 */
#[Group('security')]
final class Psr16CircuitBreakerStoreTest extends TestCase
{
    protected function setUp(): void
    {
        GadgetProbe::reset();
    }

    public function testUnknownDriverStartsClosedWithNoFailures(): void
    {
        $state = (new Psr16CircuitBreakerStore(new ArrayPsr16Cache()))->getState('unknown');

        $this->assertSame(0, $state->failureCount);
        $this->assertFalse($state->isOpen(new DateTimeImmutable()));
    }

    public function testRoundTripsAnOpenStateAcrossTwoStoresSharingOneBackend(): void
    {
        $backend = new ArrayPsr16Cache();
        $writer = new Psr16CircuitBreakerStore($backend);
        $reader = new Psr16CircuitBreakerStore($backend);
        $openUntil = (new DateTimeImmutable())->modify('+60 seconds');

        $writer->saveState('groq', new CircuitBreakerState(5, $openUntil));
        $state = $reader->getState('groq');

        $this->assertSame(5, $state->failureCount);
        $this->assertSame($openUntil->getTimestamp(), $state->openUntil?->getTimestamp());
        $this->assertTrue($state->isOpen(new DateTimeImmutable()));
    }

    public function testDifferentDriverIdsDoNotCollide(): void
    {
        $store = new Psr16CircuitBreakerStore(new ArrayPsr16Cache());

        $store->saveState('groq', new CircuitBreakerState(2, null));
        $store->saveState('openai', new CircuitBreakerState(4, null));

        $this->assertSame(2, $store->getState('groq')->failureCount);
        $this->assertSame(4, $store->getState('openai')->failureCount);
    }

    public function testTheValueHandedToTheBackendIsAStringNotAnObject(): void
    {
        $backend = new ArrayPsr16Cache();

        (new Psr16CircuitBreakerStore($backend))->saveState('groq', new CircuitBreakerState(1, null));

        $this->assertIsString($backend->get('llm_router.circuit_breaker.groq'));
    }

    public function testAPoisonedEntryIsNeverInstantiatedAndReadsAsClosed(): void
    {
        $backend = new ArrayPsr16Cache();
        $store = new Psr16CircuitBreakerStore($backend);

        $backend->poison('llm_router.circuit_breaker.groq', serialize(new GadgetProbe()));
        GadgetProbe::reset();

        $this->assertFalse(GadgetProbe::$instantiated);
        $this->assertSame(0, $store->getState('groq')->failureCount);
    }

    public function testAnUnavailableBackendFailsOpenAndIsLogged(): void
    {
        $backend = new ArrayPsr16Cache();
        $logger = new RecordingLogger();
        $store = new Psr16CircuitBreakerStore($backend, logger: $logger);

        $store->saveState('groq', new CircuitBreakerState(9, (new DateTimeImmutable())->modify('+60 seconds')));
        $backend->down = true;

        $this->assertFalse($store->getState('groq')->isOpen(new DateTimeImmutable()));
        $this->assertNotEmpty($logger->records);
    }
}
