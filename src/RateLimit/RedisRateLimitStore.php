<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\RateLimit;

use DateTimeImmutable;
use Redis;

/**
 * RateLimitStoreInterface backed by Redis, sharing the usage window across requests and workers.
 *
 * Counters live in a Redis hash keyed by fixed window index (floor(now / $windowSeconds)),
 * so a window rolls over by moving to a new key rather than by anyone rewriting the old one,
 * and the key expires on its own once that window is behind us.
 *
 * Concurrency: implements AtomicRateLimitStoreInterface, so RateLimitedDriver reserves slots
 * with HINCRBY (compensating with a decrement when the reservation would breach the ceiling)
 * instead of the read-modify-write getWindow()/saveWindow() pair, which loses increments when
 * two workers interleave. The legacy pair is kept for source compatibility with callers and
 * third-party code written against the base interface.
 *
 * Failure semantics: fail-closed. Redis errors propagate — a quota is a protection mechanism,
 * and silently admitting unlimited traffic because the coordination backend blinked is the one
 * degraded mode this store must not choose on the caller's behalf.
 *
 * Encoding: counters are plain integers and the window start a Unix timestamp. Nothing here is
 * PHP-serialized, so a hostile or corrupted Redis value can never drive object instantiation.
 */
final class RedisRateLimitStore implements AtomicRateLimitStoreInterface
{
    private const FIELD_REQUESTS = 'requests';
    private const FIELD_TOKENS = 'tokens';

    /**
     * @param int $windowSeconds Fixed-window length used by getWindow()/saveWindow(); keep it equal
     *   to RateLimitedDriver's own $windowSeconds. tryAcquire()/addTokens() take it per call instead.
     */
    public function __construct(
        private readonly Redis $redis,
        private readonly string $prefix = 'llm_router:rate_limit:',
        private readonly int $ttlSeconds = 120,
        private readonly int $windowSeconds = 60,
    ) {}

    public function getWindow(string $driverId): ?RateLimitWindow
    {
        return $this->readWindow($driverId, $this->windowSeconds);
    }

    public function saveWindow(string $driverId, RateLimitWindow $window): void
    {
        $key = $this->windowKey($driverId, $this->windowSeconds);

        $this->redis->hMSet($key, [
            self::FIELD_REQUESTS => $window->requestCount,
            self::FIELD_TOKENS => $window->tokenCount,
        ]);
        $this->redis->expire($key, $this->ttlFor($this->windowSeconds));
    }

    public function tryAcquire(
        string $driverId,
        int $windowSeconds,
        ?int $maxRequests,
        ?int $maxTokens,
    ): ?RateLimitWindow {
        $key = $this->windowKey($driverId, $windowSeconds);

        // Tokens are only ever known after the call, so the ceiling is checked
        // against what the window has already accumulated rather than reserved.
        if ($maxTokens !== null) {
            $tokens = (int) $this->redis->hGet($key, self::FIELD_TOKENS);
            if ($tokens >= $maxTokens) {
                return null;
            }
        }

        $requests = $this->redis->hIncrBy($key, self::FIELD_REQUESTS, 1);
        $this->redis->expire($key, $this->ttlFor($windowSeconds));

        if ($maxRequests !== null && $requests > $maxRequests) {
            // Over the ceiling: give the slot back so a rejected attempt never
            // permanently consumes quota. Compensating rather than checking
            // first is what keeps this free of the lost-update race — a
            // concurrent worker can transiently observe the inflated count and
            // back off, but no worker can ever under-count.
            $this->redis->hIncrBy($key, self::FIELD_REQUESTS, -1);
            return null;
        }

        return new RateLimitWindow(
            $this->windowStart($windowSeconds),
            $requests,
            (int) $this->redis->hGet($key, self::FIELD_TOKENS),
        );
    }

    public function addTokens(string $driverId, int $windowSeconds, int $tokens): void
    {
        if ($tokens === 0) {
            return;
        }

        $key = $this->windowKey($driverId, $windowSeconds);
        $this->redis->hIncrBy($key, self::FIELD_TOKENS, $tokens);
        $this->redis->expire($key, $this->ttlFor($windowSeconds));
    }

    private function readWindow(string $driverId, int $windowSeconds): ?RateLimitWindow
    {
        // Cast, not a type check: a phpredis connection configured to report
        // errors by return value hands back false rather than throwing, and an
        // absent key is an empty hash either way.
        $raw = (array) $this->redis->hGetAll($this->windowKey($driverId, $windowSeconds));
        if ($raw === []) {
            return null;
        }

        return new RateLimitWindow(
            $this->windowStart($windowSeconds),
            (int) ($raw[self::FIELD_REQUESTS] ?? 0),
            (int) ($raw[self::FIELD_TOKENS] ?? 0),
        );
    }

    private function windowKey(string $driverId, int $windowSeconds): string
    {
        return $this->prefix . $driverId . ':' . $this->windowIndex($windowSeconds);
    }

    private function windowIndex(int $windowSeconds): int
    {
        return $windowSeconds > 0
            ? intdiv(time(), $windowSeconds)
            : time();
    }

    private function windowStart(int $windowSeconds): DateTimeImmutable
    {
        $start = $windowSeconds > 0
            ? $this->windowIndex($windowSeconds) * $windowSeconds
            : time();

        return (new DateTimeImmutable())->setTimestamp($start);
    }

    private function ttlFor(int $windowSeconds): int
    {
        return max($this->ttlSeconds, $windowSeconds * 2, 1);
    }
}
