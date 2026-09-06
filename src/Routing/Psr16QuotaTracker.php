<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Routing;

use CleatSquad\LlmRouter\Contract\Routing\QuotaTrackerInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

final class Psr16QuotaTracker implements QuotaTrackerInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix = 'llm_router.quota.',
        private readonly int $defaultTtlSeconds = 60,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    #[\Override]
    public function getQuotaRemainingRatio(string $driverId): ?float
    {
        try {
            $raw = $this->cache->get($this->ratioKey($driverId));
            if ($raw === null || $raw === false) {
                return null;
            }
            return (float) $raw;
        } catch (Throwable $e) {
            $this->logger?->warning('llm_router.quota_tracker.store_unavailable', [
                'operation' => 'get_ratio',
                'driver_id' => $driverId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    #[\Override]
    public function isQuotaExceeded(string $driverId): bool
    {
        try {
            if ($this->cache->has($this->exceededKey($driverId))) {
                return true;
            }

            $ratio = $this->getQuotaRemainingRatio($driverId);
            return $ratio !== null && $ratio <= 0.0;
        } catch (Throwable $e) {
            $this->logger?->warning('llm_router.quota_tracker.store_unavailable', [
                'operation' => 'is_exceeded',
                'driver_id' => $driverId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function setQuotaRemainingRatio(string $driverId, float $ratio, ?int $ttlSeconds = null): void
    {
        $ttl = $ttlSeconds ?? $this->defaultTtlSeconds;
        $clamped = min(max($ratio, 0.0), 1.0);

        try {
            $this->cache->set($this->ratioKey($driverId), (string) $clamped, $ttl);

            if ($clamped <= 0.0) {
                $this->cache->set($this->exceededKey($driverId), '1', $ttl);
            } else {
                $this->cache->delete($this->exceededKey($driverId));
            }
        } catch (Throwable $e) {
            $this->logger?->warning('llm_router.quota_tracker.store_unavailable', [
                'operation' => 'set_ratio',
                'driver_id' => $driverId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function setQuotaExceeded(string $driverId, ?int $ttlSeconds = null): void
    {
        $ttl = $ttlSeconds ?? $this->defaultTtlSeconds;

        try {
            $this->cache->set($this->exceededKey($driverId), '1', $ttl);
            $this->cache->set($this->ratioKey($driverId), '0.0', $ttl);
        } catch (Throwable $e) {
            $this->logger?->warning('llm_router.quota_tracker.store_unavailable', [
                'operation' => 'set_exceeded',
                'driver_id' => $driverId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function reset(string $driverId): void
    {
        try {
            $this->cache->delete($this->ratioKey($driverId));
            $this->cache->delete($this->exceededKey($driverId));
        } catch (Throwable $e) {
            $this->logger?->warning('llm_router.quota_tracker.store_unavailable', [
                'operation' => 'reset',
                'driver_id' => $driverId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function ratioKey(string $driverId): string
    {
        return str_replace(['{', '}', '(', ')', '/', '\\', '@', ':'], '_', $this->prefix . $driverId . '.ratio');
    }

    private function exceededKey(string $driverId): string
    {
        return str_replace(['{', '}', '(', ')', '/', '\\', '@', ':'], '_', $this->prefix . $driverId . '.exceeded');
    }
}
