<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Routing;

use CleatSquad\LlmRouter\Routing\Psr16QuotaTracker;
use CleatSquad\LlmRouter\Tests\Fixtures\ArrayPsr16Cache;
use CleatSquad\LlmRouter\Tests\Fixtures\RecordingLogger;
use PHPUnit\Framework\TestCase;

final class Psr16QuotaTrackerTest extends TestCase
{
    public function testUnknownDriverHasNoQuotaLimit(): void
    {
        $tracker = new Psr16QuotaTracker(new ArrayPsr16Cache());

        $this->assertNull($tracker->getQuotaRemainingRatio('unknown'));
        $this->assertFalse($tracker->isQuotaExceeded('unknown'));
    }

    public function testSetsAndGetsQuotaRatio(): void
    {
        $tracker = new Psr16QuotaTracker(new ArrayPsr16Cache());

        $tracker->setQuotaRemainingRatio('groq', 0.8, 60);

        $this->assertSame(0.8, $tracker->getQuotaRemainingRatio('groq'));
        $this->assertFalse($tracker->isQuotaExceeded('groq'));
    }

    public function testExceededWhenRatioIsZero(): void
    {
        $tracker = new Psr16QuotaTracker(new ArrayPsr16Cache());

        $tracker->setQuotaRemainingRatio('mistral', 0.0, 60);

        $this->assertSame(0.0, $tracker->getQuotaRemainingRatio('mistral'));
        $this->assertTrue($tracker->isQuotaExceeded('mistral'));
    }

    public function testExplicitSetQuotaExceeded(): void
    {
        $tracker = new Psr16QuotaTracker(new ArrayPsr16Cache());

        $tracker->setQuotaExceeded('deepseek', 60);

        $this->assertSame(0.0, $tracker->getQuotaRemainingRatio('deepseek'));
        $this->assertTrue($tracker->isQuotaExceeded('deepseek'));
    }

    public function testResetClearsQuotaStatus(): void
    {
        $tracker = new Psr16QuotaTracker(new ArrayPsr16Cache());

        $tracker->setQuotaExceeded('openai', 60);
        $this->assertTrue($tracker->isQuotaExceeded('openai'));

        $tracker->reset('openai');
        $this->assertNull($tracker->getQuotaRemainingRatio('openai'));
        $this->assertFalse($tracker->isQuotaExceeded('openai'));
    }

    public function testAnUnavailableBackendFailsOpenAndIsLogged(): void
    {
        $backend = new ArrayPsr16Cache();
        $logger = new RecordingLogger();
        $tracker = new Psr16QuotaTracker($backend, logger: $logger);

        $tracker->setQuotaExceeded('groq', 60);
        $backend->down = true;

        $this->assertFalse($tracker->isQuotaExceeded('groq'));
        $this->assertNull($tracker->getQuotaRemainingRatio('groq'));
        $this->assertNotEmpty($logger->records);
    }
}
