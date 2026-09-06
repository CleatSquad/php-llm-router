<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Http;

use CleatSquad\LlmRouter\Http\RateLimitHeaderParser;
use PHPUnit\Framework\TestCase;

final class RateLimitHeaderParserTest extends TestCase
{
    public function testParsesEmptyHeaders(): void
    {
        $parsed = RateLimitHeaderParser::parse([]);

        $this->assertNull($parsed['remainingRequests']);
        $this->assertNull($parsed['limitRequests']);
        $this->assertNull($parsed['remainingTokens']);
        $this->assertNull($parsed['limitTokens']);
        $this->assertNull($parsed['resetSeconds']);
        $this->assertNull($parsed['retryAfterSeconds']);
        $this->assertNull($parsed['quotaRatio']);
        $this->assertFalse($parsed['isExhausted']);
    }

    public function testParsesOpenAiRateLimitHeaders(): void
    {
        $headers = [
            'x-ratelimit-remaining-requests' => '499',
            'x-ratelimit-limit-requests' => '500',
            'x-ratelimit-remaining-tokens' => '29000',
            'x-ratelimit-limit-tokens' => '30000',
            'x-ratelimit-reset-requests' => '2ms',
            'x-ratelimit-reset-tokens' => '6s',
        ];

        $parsed = RateLimitHeaderParser::parse($headers);

        $this->assertSame(499, $parsed['remainingRequests']);
        $this->assertSame(500, $parsed['limitRequests']);
        $this->assertSame(29000, $parsed['remainingTokens']);
        $this->assertSame(30000, $parsed['limitTokens']);
        $this->assertSame(1, $parsed['resetSeconds']);
        $this->assertFalse($parsed['isExhausted']);
        $this->assertNotNull($parsed['quotaRatio']);
        $this->assertEqualsWithDelta(29000 / 30000, $parsed['quotaRatio'], 0.001);
    }

    public function testParsesAnthropicRateLimitHeaders(): void
    {
        $headers = [
            'anthropic-ratelimit-requests-remaining' => '48',
            'anthropic-ratelimit-requests-limit' => '50',
            'anthropic-ratelimit-tokens-remaining' => '35000',
            'anthropic-ratelimit-tokens-limit' => '40000',
            'anthropic-ratelimit-requests-reset' => '2026-09-01T08:56:00.000Z',
        ];

        $parsed = RateLimitHeaderParser::parse($headers);

        $this->assertSame(48, $parsed['remainingRequests']);
        $this->assertSame(50, $parsed['limitRequests']);
        $this->assertSame(35000, $parsed['remainingTokens']);
        $this->assertSame(40000, $parsed['limitTokens']);
        $this->assertFalse($parsed['isExhausted']);
        $this->assertNotNull($parsed['quotaRatio']);
        $this->assertEqualsWithDelta(35000 / 40000, $parsed['quotaRatio'], 0.001);
    }

    public function testExhaustedWhenRemainingRequestsZero(): void
    {
        $headers = [
            'x-ratelimit-remaining-requests' => '0',
            'x-ratelimit-limit-requests' => '500',
            'x-ratelimit-reset-requests' => '30s',
        ];

        $parsed = RateLimitHeaderParser::parse($headers);

        $this->assertSame(0, $parsed['remainingRequests']);
        $this->assertSame(30, $parsed['resetSeconds']);
        $this->assertSame(0.0, $parsed['quotaRatio']);
        $this->assertTrue($parsed['isExhausted']);
    }

    public function testExhaustedWhenRemainingTokensZero(): void
    {
        $headers = [
            'x-ratelimit-remaining-tokens' => '0',
            'x-ratelimit-limit-tokens' => '10000',
        ];

        $parsed = RateLimitHeaderParser::parse($headers);

        $this->assertSame(0, $parsed['remainingTokens']);
        $this->assertSame(0.0, $parsed['quotaRatio']);
        $this->assertTrue($parsed['isExhausted']);
    }

    public function testParsesRetryAfterHeader(): void
    {
        $headers = [
            'retry-after' => '15',
        ];

        $parsed = RateLimitHeaderParser::parse($headers);

        $this->assertSame(15, $parsed['retryAfterSeconds']);
        $this->assertSame(15, $parsed['resetSeconds']);
    }

    public function testParsesResetDurationFormats(): void
    {
        $this->assertSame(6, RateLimitHeaderParser::parseResetDuration('6s'));
        $this->assertSame(90, RateLimitHeaderParser::parseResetDuration('1m30s'));
        $this->assertSame(1, RateLimitHeaderParser::parseResetDuration('500ms'));
        $this->assertSame(3600, RateLimitHeaderParser::parseResetDuration('1h'));
        $this->assertSame(45, RateLimitHeaderParser::parseResetDuration('45'));
    }
}
