<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Routing;

use CleatSquad\LlmRouter\Routing\RedisQuotaTracker;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Redis;

#[RequiresPhpExtension('redis')]
final class RedisQuotaTrackerTest extends TestCase
{
    public function testUnknownDriverHasNoQuotaLimit(): void
    {
        $tracker = new RedisQuotaTracker(new Redis());

        $this->assertNull($tracker->getQuotaRemainingRatio('unknown'));
        $this->assertFalse($tracker->isQuotaExceeded('unknown'));
    }

    public function testSetsAndGetsQuotaRatio(): void
    {
        $tracker = new RedisQuotaTracker(new Redis());

        $tracker->setQuotaRemainingRatio('groq', 0.75, 60);

        $this->assertSame(0.75, $tracker->getQuotaRemainingRatio('groq'));
        $this->assertFalse($tracker->isQuotaExceeded('groq'));
    }

    public function testExceededWhenRatioIsZero(): void
    {
        $tracker = new RedisQuotaTracker(new Redis());

        $tracker->setQuotaRemainingRatio('mistral', 0.0, 60);

        $this->assertSame(0.0, $tracker->getQuotaRemainingRatio('mistral'));
        $this->assertTrue($tracker->isQuotaExceeded('mistral'));
    }

    public function testExplicitSetQuotaExceeded(): void
    {
        $tracker = new RedisQuotaTracker(new Redis());

        $tracker->setQuotaExceeded('deepseek', 60);

        $this->assertSame(0.0, $tracker->getQuotaRemainingRatio('deepseek'));
        $this->assertTrue($tracker->isQuotaExceeded('deepseek'));
    }

    public function testResetClearsQuotaStatus(): void
    {
        $tracker = new RedisQuotaTracker(new Redis());

        $tracker->setQuotaExceeded('openai', 60);
        $this->assertTrue($tracker->isQuotaExceeded('openai'));

        $tracker->reset('openai');
        $this->assertNull($tracker->getQuotaRemainingRatio('openai'));
        $this->assertFalse($tracker->isQuotaExceeded('openai'));
    }

    public function testDifferentDriversDoNotCollide(): void
    {
        $tracker = new RedisQuotaTracker(new Redis());

        $tracker->setQuotaRemainingRatio('gemini', 0.5, 60);
        $tracker->setQuotaExceeded('claude', 60);

        $this->assertSame(0.5, $tracker->getQuotaRemainingRatio('gemini'));
        $this->assertFalse($tracker->isQuotaExceeded('gemini'));

        $this->assertTrue($tracker->isQuotaExceeded('claude'));
    }
}
