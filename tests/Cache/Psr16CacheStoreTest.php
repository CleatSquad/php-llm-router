<?php

declare(strict_types=1);

namespace LlmRouter\Tests\Cache;

use LlmRouter\Cache\Psr16CacheStore;
use LlmRouter\DTO\LLMResponse;
use LlmRouter\Tests\Fixtures\ArrayPsr16Cache;
use LlmRouter\Tests\Fixtures\GadgetProbe;
use LlmRouter\Tests\Fixtures\RecordingLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Invariant protected: a deployment without Redis gets a shared cache with the
 *   same guarantees as the Redis one — JSON only, fail-open, never a value that
 *   drives instantiation.
 * Bug covered: without this store, "no Redis" silently meant a process-local
 *   cache, i.e. no sharing at all across PHP-FPM workers.
 * Type: feature + security.
 */
#[Group('security')]
final class Psr16CacheStoreTest extends TestCase
{
    protected function setUp(): void
    {
        GadgetProbe::reset();
    }

    private function response(string $content = 'hi'): LLMResponse
    {
        return new LLMResponse(
            content: $content,
            model: 'fake-model',
            promptTokens: 1,
            completionTokens: 2,
            totalTokens: 3,
            costUsd: 0.01,
            latencyMs: 10,
            toolCalls: null,
            finishReason: 'stop',
            metadata: ['k' => 'v'],
        );
    }

    public function testMissingKeyReturnsNull(): void
    {
        $store = new Psr16CacheStore(new ArrayPsr16Cache());

        $this->assertNull($store->get('missing'));
    }

    public function testRoundTripsAStoredResponse(): void
    {
        $store = new Psr16CacheStore(new ArrayPsr16Cache());

        $store->set('key', $this->response('cached'), 60);

        $this->assertSame('cached', $store->get('key')?->content);
        $this->assertSame(['k' => 'v'], $store->get('key')?->metadata);
    }

    public function testDifferentKeysDoNotCollide(): void
    {
        $store = new Psr16CacheStore(new ArrayPsr16Cache());

        $store->set('a', $this->response('alpha'), 60);
        $store->set('b', $this->response('beta'), 60);

        $this->assertSame('alpha', $store->get('a')?->content);
        $this->assertSame('beta', $store->get('b')?->content);
    }

    public function testTwoStoresShareOneBackend(): void
    {
        $backend = new ArrayPsr16Cache();
        $writer = new Psr16CacheStore($backend);
        $reader = new Psr16CacheStore($backend);

        $writer->set('key', $this->response('shared'), 60);

        $this->assertSame('shared', $reader->get('key')?->content);
    }

    public function testAnExpiredEntryIsNoLongerReadable(): void
    {
        $store = new Psr16CacheStore(new ArrayPsr16Cache());

        $store->set('key', $this->response(), 1);
        $this->assertNotNull($store->get('key'));

        usleep(1_100_000);

        $this->assertNull($store->get('key'));
    }

    public function testTheValueHandedToTheBackendIsAStringNotAnObject(): void
    {
        $backend = new ArrayPsr16Cache();
        $store = new Psr16CacheStore($backend);

        $store->set('key', $this->response(), 60);

        // Several PSR-16 adapters serialize whatever they are given; handing
        // them the DTO itself would put a PHP-serialized object back on the
        // wire and reintroduce the deserialization exposure.
        $this->assertIsString($backend->get('llm_router.cache.key'));
    }

    public function testAPoisonedEntryIsNeverInstantiated(): void
    {
        $backend = new ArrayPsr16Cache();
        $store = new Psr16CacheStore($backend);

        $backend->poison('llm_router.cache.key', serialize(new GadgetProbe()));
        GadgetProbe::reset();

        $this->assertNull($store->get('key'));
        $this->assertFalse(GadgetProbe::$instantiated);
    }

    public function testAnObjectSittingInTheBackendIsIgnoredRatherThanReturned(): void
    {
        $backend = new ArrayPsr16Cache();
        $store = new Psr16CacheStore($backend);

        $backend->poison('llm_router.cache.key', new GadgetProbe());
        GadgetProbe::reset();

        $this->assertNull($store->get('key'), 'only strings written by this store are accepted');
    }

    public function testACorruptEntryReadsAsAMiss(): void
    {
        $backend = new ArrayPsr16Cache();
        $store = new Psr16CacheStore($backend);

        $backend->poison('llm_router.cache.key', '{"content":"hi",');

        $this->assertNull($store->get('key'));
    }

    public function testAnUnavailableBackendFailsOpenAndIsLogged(): void
    {
        $backend = new ArrayPsr16Cache();
        $logger = new RecordingLogger();
        $store = new Psr16CacheStore($backend, logger: $logger);

        $backend->down = true;

        $this->assertNull($store->get('key'));
        $store->set('key', $this->response(), 60);

        $this->assertNotEmpty($logger->records);
    }

    public function testABackendComingBackAfterAnOutageCachesAgain(): void
    {
        $backend = new ArrayPsr16Cache();
        $store = new Psr16CacheStore($backend);

        $backend->down = true;
        $store->set('key', $this->response('during outage'), 60);
        $this->assertNull($store->get('key'));

        $backend->down = false;
        $store->set('key', $this->response('after recovery'), 60);

        $this->assertSame('after recovery', $store->get('key')?->content);
    }

    public function testReservedPsr16CharactersAreStrippedFromTheKey(): void
    {
        $backend = new ArrayPsr16Cache();
        $store = new Psr16CacheStore($backend, prefix: 'app:llm{cache}/');

        $store->set('abc', $this->response(), 60);

        // PSR-16 forbids {}()/\@: in keys; the store must sanitise the prefix
        // rather than let a caller's naming convention throw InvalidArgument.
        $this->assertIsString($backend->get('app_llm_cache__abc'));
        $this->assertNotNull($store->get('abc'), 'sanitising stays stable between set() and get()');
    }
}
