<?php

declare(strict_types=1);

namespace LlmRouter\Exception;

use RuntimeException;

/**
 * Thrown by FailoverDriver when every candidate driver failed. Carries the
 * per-driver failures so callers can react selectively (e.g. alert only if
 * every cloud provider is down, not just the local one) instead of parsing
 * a concatenated message.
 */
final class AllDriversFailedException extends RuntimeException
{
    /**
     * @param array<int, array{driverId: string, exception: RuntimeException}> $failures
     */
    public function __construct(private readonly array $failures)
    {
        parent::__construct('All LLM drivers failed: ' . implode(' | ', array_map(
            static fn (array $f): string => $f['driverId'] . ': ' . $f['exception']->getMessage(),
            $failures
        )));
    }

    /**
     * @return array<int, array{driverId: string, exception: RuntimeException}>
     */
    public function getFailures(): array
    {
        return $this->failures;
    }
}
