<?php

declare(strict_types=1);

namespace LlmRouter\Driver\Concern;

use GuzzleHttp\Exception\RequestException;
use LlmRouter\Exception\RateLimitException;
use LlmRouter\Http\RetryAfterParser;

trait HandlesHttpRateLimit
{
    protected function handleHttpRateLimit(RequestException $e, string $driverName = 'Driver'): void
    {
        $response = $e->getResponse();
        if ($response !== null && $response->getStatusCode() === 429) {
            $retryAfterSeconds = RetryAfterParser::parse($response->getHeaderLine('Retry-After'));
            throw new RateLimitException(
                message: sprintf(
                    '%s rate limit exceeded%s',
                    $driverName,
                    $retryAfterSeconds !== null ? sprintf(' (retry after %d seconds)', $retryAfterSeconds) : ''
                ),
                retryAfterSeconds: $retryAfterSeconds,
                statusCode: 429,
                previous: $e,
            );
        }
    }
}
