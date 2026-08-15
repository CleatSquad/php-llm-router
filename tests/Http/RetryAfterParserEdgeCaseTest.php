<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Http;

use CleatSquad\LlmRouter\Http\RetryAfterParser;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Invariant protected: Retry-After is only ever turned into a delay we would
 *   actually be willing to wait, and anything unrecognised degrades to "no
 *   opinion" (null) rather than to a wrong number.
 * Bug covered: none open — pins the parser against both header forms RFC 9110
 *   allows, plus the malformed values providers do send in practice.
 * Type: characterisation + edge cases.
 */
final class RetryAfterParserEdgeCaseTest extends TestCase
{
    public function testZeroIsADelayNotAnAbsentValue(): void
    {
        $this->assertSame(0, RetryAfterParser::parse('0'));
    }

    public function testSurroundingWhitespaceIsIgnored(): void
    {
        $this->assertSame(30, RetryAfterParser::parse("  30\r\n"));
    }

    public function testALargeDelayIsPassedThroughForTheCallerToCap(): void
    {
        // The parser reports what the provider said; capping is a policy
        // decision and belongs to whoever waits (RetryingDriver caps at
        // maxDelaySeconds, the breaker uses it as a cooldown).
        $this->assertSame(86_400, RetryAfterParser::parse('86400'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unparseableValues(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => ['   '],
            'not a number or date' => ['soon'],
            'fractional seconds' => ['1.5'],
            'seconds with a unit' => ['30s'],
            'comma-separated list' => ['30, 60'],
            'plus-prefixed' => ['+30'],
            'hex' => ['0x1E'],
        ];
    }

    #[DataProvider('unparseableValues')]
    public function testUnparseableValuesYieldNoOpinion(string $value): void
    {
        $parsed = RetryAfterParser::parse($value);

        $this->assertNull(
            $parsed,
            sprintf('"%s" must read as "no usable Retry-After", got %s', $value, var_export($parsed, true))
        );
    }

    public function testAMissingHeaderYieldsNoOpinion(): void
    {
        $this->assertNull(RetryAfterParser::parse(null));
    }

    public function testANegativeDelayIsClampedToZeroRatherThanScheduledInThePast(): void
    {
        $this->assertSame(0, RetryAfterParser::parse('-1'));
        $this->assertSame(0, RetryAfterParser::parse('-3600'));
    }

    public function testAnHttpDateInTheFutureBecomesTheRemainingSeconds(): void
    {
        $in120s = (new DateTimeImmutable('+120 seconds'))->format('D, d M Y H:i:s \G\M\T');

        $parsed = RetryAfterParser::parse($in120s);

        $this->assertNotNull($parsed);
        $this->assertGreaterThanOrEqual(118, $parsed);
        $this->assertLessThanOrEqual(121, $parsed);
    }

    public function testAnHttpDateInThePastMeansRetryNow(): void
    {
        $past = (new DateTimeImmutable('-1 hour'))->format('D, d M Y H:i:s \G\M\T');

        $this->assertSame(0, RetryAfterParser::parse($past));
    }

    public function testTheOtherDateFormatsRfc9110AllowsAreAlsoAccepted(): void
    {
        $future = new DateTimeImmutable('+300 seconds');

        foreach ([
            $future->format('D, d M Y H:i:s \G\M\T'),   // IMF-fixdate
            $future->format('l, d-M-y H:i:s \G\M\T'),   // obsolete RFC 850
            $future->format('c'),                       // ISO 8601, seen in the wild
        ] as $formatted) {
            $parsed = RetryAfterParser::parse($formatted);

            $this->assertNotNull($parsed, sprintf('"%s" should parse as a date', $formatted));
            $this->assertGreaterThan(0, $parsed);
            $this->assertLessThanOrEqual(301, $parsed);
        }
    }
}
