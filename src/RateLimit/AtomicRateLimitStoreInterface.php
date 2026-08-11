<?php

declare(strict_types=1);

namespace LlmRouter\RateLimit;

/**
 * Opt-in extension of RateLimitStoreInterface for backends offering a real
 * atomic increment (Redis HINCRBY, SQL UPDATE ... RETURNING, ...).
 *
 * The base getWindow()/saveWindow() pair is a read-modify-write: two workers
 * reading the same window before either writes both save requestCount+1, so
 * one request is lost from the count and the quota silently over-admits.
 * That is harmless for a process-local store (no concurrency by construction)
 * but wrong for a store shared across workers, which is exactly what such a
 * store is for.
 *
 * RateLimitedDriver uses this path whenever its store implements this
 * interface, and falls back to the read-modify-write one otherwise, so
 * third-party stores written against the base interface keep working.
 */
interface AtomicRateLimitStoreInterface extends RateLimitStoreInterface
{
    /**
     * Atomically reserves one request slot in the current window.
     *
     * Returns the window as it stands *after* the reservation, or null when
     * the reservation would have exceeded $maxRequests/$maxTokens — in which
     * case the implementation must leave the stored counters unchanged.
     *
     * @param int      $windowSeconds Length of the fixed window, > 0.
     * @param int|null $maxRequests   Request ceiling, or null for unlimited.
     * @param int|null $maxTokens     Token ceiling, or null for unlimited.
     */
    public function tryAcquire(
        string $driverId,
        int $windowSeconds,
        ?int $maxRequests,
        ?int $maxTokens,
    ): ?RateLimitWindow;

    /**
     * Atomically adds observed token usage to the current window.
     *
     * Called after the provider answered, once the real token count is known;
     * tryAcquire() only reserves the request slot.
     */
    public function addTokens(string $driverId, int $windowSeconds, int $tokens): void;
}
