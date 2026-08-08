<?php

declare(strict_types=1);

namespace LlmRouter\Tests\Http;

use DateTimeImmutable;
use LlmRouter\Http\RetryAfterParser;
use PHPUnit\Framework\TestCase;

final class RetryAfterParserTest extends TestCase
{
    public function testParsesDeltaSeconds(): void
    {
        $this->assertSame(3442, RetryAfterParser::parse('3442'));
    }

    public function testReturnsNullOnNullOrEmptyOrInvalid(): void
    {
        $this->assertNull(RetryAfterParser::parse(null));
        $this->assertNull(RetryAfterParser::parse(''));
        $this->assertNull(RetryAfterParser::parse('   '));
        $this->assertNull(RetryAfterParser::parse('invalid'));
    }

    public function testReturnsZeroOnNegativeValue(): void
    {
        $this->assertSame(0, RetryAfterParser::parse('-1'));
        $this->assertSame(0, RetryAfterParser::parse('-3442'));
    }

    public function testParsesValidHttpDateInFuture(): void
    {
        $future = (new DateTimeImmutable('+60 seconds'))->format(\DateTimeInterface::RFC7231);
        $parsed = RetryAfterParser::parse($future);
        $this->assertNotNull($parsed);
        $this->assertGreaterThanOrEqual(58, $parsed);
        $this->assertLessThanOrEqual(62, $parsed);
    }

    public function testParsesHttpDateInPastReturnsZero(): void
    {
        $past = (new DateTimeImmutable('-60 seconds'))->format(\DateTimeInterface::RFC7231);
        $this->assertSame(0, RetryAfterParser::parse($past));
    }
}
