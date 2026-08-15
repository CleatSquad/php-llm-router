<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Cache;

use CleatSquad\LlmRouter\Cache\RedisCacheStore;
use CleatSquad\LlmRouter\DTO\LLMResponse;
use CleatSquad\LlmRouter\Tests\Fixtures\GadgetProbe;
use CleatSquad\LlmRouter\Tests\Fixtures\RecordingLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Redis;
use RuntimeException;

/**
 * Invariant protected: a value read back from the shared cache never decides
 *   which PHP class gets instantiated.
 * Bug covered: the store used unserialize($raw) and only checked the resulting
 *   type afterwards — by which point __wakeup()/__destruct() of whatever class
 *   the payload named had already run. Anyone able to write to Redis (a shared
 *   or misconfigured instance, a compromised neighbour) had an RCE primitive.
 * Type: security + regression.
 */
#[Group('security')]
final class RedisCacheStoreSecurityTest extends TestCase
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

    /**
     * Writes a raw value straight into the backend, the way an attacker or a
     * corrupted entry would — bypassing set().
     */
    private function poison(Redis $redis, string $key, string $payload): void
    {
        $redis->setex('llm_router:cache:' . $key, 60, $payload);

        // Building the payload above legitimately constructs the probe; only
        // what happens from here on — inside get() — is under test.
        GadgetProbe::reset();
    }

    public function testAForbiddenClassPayloadIsNeverInstantiated(): void
    {
        $redis = new Redis();
        $store = new RedisCacheStore($redis);

        $this->poison($redis, 'k', serialize(new GadgetProbe()));

        $this->assertNull($store->get('k'));
        $this->assertFalse(GadgetProbe::$instantiated, 'decoding must not construct the gadget class');
    }

    public function testAGadgetNestedInsideAnAllowedShapeIsNeverInstantiated(): void
    {
        $redis = new Redis();
        $store = new RedisCacheStore($redis);

        // The outer shape is exactly what the store expects; the payload only
        // smuggles the gadget in a nested field.
        $this->poison($redis, 'k', serialize([
            'content' => 'hi',
            'model' => 'm',
            'finishReason' => 'stop',
            'metadata' => ['nested' => new GadgetProbe()],
        ]));

        $this->assertNull($store->get('k'));
        $this->assertFalse(GadgetProbe::$instantiated);
    }

    public function testALegacyPhpSerializedEntryIsRejectedRatherThanRestored(): void
    {
        $redis = new Redis();
        $store = new RedisCacheStore($redis);

        // Entries written by v1.13.1 are PHP-serialized. They are not readable
        // by the JSON codec — by design, since accepting them would mean
        // keeping the unserialize() path alive.
        $this->poison($redis, 'k', serialize($this->response()));

        $this->assertNull($store->get('k'));
    }

    public function testACorruptOrTruncatedPayloadReadsAsAMiss(): void
    {
        $redis = new Redis();
        $store = new RedisCacheStore($redis);

        $this->poison($redis, 'truncated', '{"content":"hi","mod');
        $this->poison($redis, 'malformed', 'not json at all');
        $this->poison($redis, 'scalar', '42');
        $this->poison($redis, 'wrong-shape', '{"content":123,"model":null}');

        $this->assertNull($store->get('truncated'));
        $this->assertNull($store->get('malformed'));
        $this->assertNull($store->get('scalar'));
        $this->assertNull($store->get('wrong-shape'));
    }

    public function testAValidEntryStillRoundTripsEveryField(): void
    {
        $store = new RedisCacheStore(new Redis());
        $original = new LLMResponse(
            content: 'hello',
            model: 'gpt-4o',
            promptTokens: 11,
            completionTokens: 22,
            totalTokens: 33,
            costUsd: 0.125,
            latencyMs: 420,
            toolCalls: [['id' => 'call_1', 'type' => 'function']],
            finishReason: 'tool_calls',
            metadata: ['provider' => 'openai'],
        );

        $store->set('k', $original, 60);
        $cached = $store->get('k');

        $this->assertNotNull($cached);
        $this->assertSame($original->content, $cached->content);
        $this->assertSame($original->model, $cached->model);
        $this->assertSame($original->promptTokens, $cached->promptTokens);
        $this->assertSame($original->completionTokens, $cached->completionTokens);
        $this->assertSame($original->totalTokens, $cached->totalTokens);
        $this->assertSame($original->costUsd, $cached->costUsd);
        $this->assertSame($original->latencyMs, $cached->latencyMs);
        $this->assertSame($original->toolCalls, $cached->toolCalls);
        $this->assertSame($original->finishReason, $cached->finishReason);
        $this->assertSame($original->metadata, $cached->metadata);
    }

    public function testAnUnavailableBackendFailsOpenOnReadInsteadOfBreakingTheCall(): void
    {
        $logger = new RecordingLogger();
        $store = new RedisCacheStore(new class extends Redis {
            public function get(string $key): string|false
            {
                throw new RuntimeException('Connection timed out');
            }
        }, logger: $logger);

        $this->assertNull($store->get('k'), 'a store outage degrades to a cache miss');
        $this->assertNotEmpty($logger->records, 'fail-open must not mean failing silently');
    }

    public function testAnUnavailableBackendFailsOpenOnWriteInsteadOfBreakingTheCall(): void
    {
        $logger = new RecordingLogger();
        $store = new RedisCacheStore(new class extends Redis {
            public function setex(string $key, int $ttl, string $value): bool
            {
                throw new RuntimeException('READONLY You can not write against a read only replica');
            }
        }, logger: $logger);

        $store->set('k', $this->response(), 60);

        $this->assertNotEmpty($logger->records);
    }

    public function testAStoreThatRecoversStartsCachingAgain(): void
    {
        $redis = new class extends Redis {
            public bool $down = true;

            public function get(string $key): string|false
            {
                if ($this->down) {
                    throw new RuntimeException('Connection refused');
                }

                return parent::get($key);
            }

            public function setex(string $key, int $ttl, string $value): bool
            {
                if ($this->down) {
                    throw new RuntimeException('Connection refused');
                }

                return parent::setex($key, $ttl, $value);
            }
        };
        $store = new RedisCacheStore($redis);

        $store->set('k', $this->response('during outage'), 60);
        $this->assertNull($store->get('k'));

        $redis->down = false;

        $store->set('k', $this->response('after recovery'), 60);
        $this->assertSame('after recovery', $store->get('k')?->content);
    }
}
