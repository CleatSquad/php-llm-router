<?php

declare(strict_types=1);

namespace LlmRouter\Tests\Fixtures;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * PSR-3 logger spy that just records every call for assertions —
 * for tests that need to verify FailoverDriver emits the expected
 * failover/all-drivers-failed log records.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var array<int, array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
