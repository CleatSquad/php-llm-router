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

        // Only try the HTTP-date form on something that actually looks like a
        // date. DateTimeImmutable happily reads "1.5" or "+30" as a relative
        // offset from now, which collapses to a delay of 0 — i.e. "retry
        // immediately", the single worst reading of a header whose whole point
        // is to make the caller wait. A malformed value must mean "no usable
        // Retry-After" (null), leaving the caller on its own backoff.
        if (preg_match('/[A-Za-z]/', $headerValue) !== 1) {
            return null;
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
