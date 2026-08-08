<?php

declare(strict_types=1);

namespace LlmRouter\Http;

use DateTimeImmutable;

final class RetryAfterParser
{
    public static function parse(?string $headerValue): ?int
    {
        if ($headerValue === null) {
            return null;
        }

        $headerValue = trim($headerValue);
        if ($headerValue === '') {
            return null;
        }

        if (ctype_digit($headerValue)) {
            return (int) $headerValue;
        }

        if (str_starts_with($headerValue, '-')) {
            $positivePart = substr($headerValue, 1);
            if (ctype_digit($positivePart)) {
                return 0;
            }
        }

        try {
            $date = new DateTimeImmutable($headerValue);
            $now = new DateTimeImmutable();
            $diff = $date->getTimestamp() - $now->getTimestamp();
            return max(0, $diff);
        } catch (\Throwable) {
            return null;
        }
    }
}
