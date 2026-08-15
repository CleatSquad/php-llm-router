<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Driver;

use CleatSquad\LlmRouter\Driver\RateLimitedDriver;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\RateLimit\InMemoryRateLimitStore;
use CleatSquad\LlmRouter\Tests\Fixtures\ControllableDriver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RateLimitedDriverTest extends TestCase
{
    private function request(): LLMRequest
    {
        return new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]);
    }

    public function testAllowsCallsUnderTheRequestLimit(): void
    {
        $inner = new ControllableDriver('fake');
        $driver = new RateLimitedDriver($inner, maxRequestsPerMinute: 2, maxWaitSeconds: 0.0);

        $driver->chat($this->request());
        $driver->chat($this->request());

        $this->assertSame(2, $inner->callCount);
    }

    public function testBlocksAndThenGivesUpPastTheRequestLimitWithNoWaitBudget(): void
    {
        $inner = new ControllableDriver('fake');
        $driver = new RateLimitedDriver($inner, maxRequestsPerMinute: 1, maxWaitSeconds: 0.0);

        $driver->chat($this->request());

        try {
            $driver->chat($this->request());
            $this->fail('expected the rate limit to trip');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Rate limit exceeded', $e->getMessage());
        }

        $this->assertSame(1, $inner->callCount, 'the second call must never reach the inner driver');
    }

    public function testEnforcesTheTokenBudgetTooNotJustTheRequestCount(): void
    {
        // Usage is only known *after* a call completes, so the budget is
        // checked against what's already been consumed going into the
        // next call — the first call itself always goes through.
        $inner = new ControllableDriver('fake', completionTokens: 100);
        $driver = new RateLimitedDriver($inner, maxTokensPerMinute: 50, maxWaitSeconds: 0.0);

        $driver->chat($this->request()); // pushes the window to 100, already past the 50 budget

        try {
            $driver->chat($this->request());
            $this->fail('expected the token budget to be exhausted');
        } catch (RuntimeException) {
        }

        $this->assertSame(1, $inner->callCount);
    }

    public function testAZeroWindowMeansEveryCallStartsAFreshWindow(): void
    {
        $inner = new ControllableDriver('fake');
        $driver = new RateLimitedDriver($inner, maxRequestsPerMinute: 1, windowSeconds: 0, maxWaitSeconds: 0.0);

        $driver->chat($this->request());
        $driver->chat($this->request());
        $driver->chat($this->request());

        $this->assertSame(3, $inner->callCount, 'a 0s window never accumulates usage across calls');
    }

    public function testUnboundedWhenNoLimitsAreConfigured(): void
    {
        $inner = new ControllableDriver('fake');
        $driver = new RateLimitedDriver($inner);

        for ($i = 0; $i < 5; $i++) {
            $driver->chat($this->request());
        }

        $this->assertSame(5, $inner->callCount);
    }

    public function testSharesQuotaAcrossInstancesViaAnExplicitStore(): void
    {
        $store = new InMemoryRateLimitStore();
        $inner = new ControllableDriver('fake');
        $first = new RateLimitedDriver($inner, $store, maxRequestsPerMinute: 1, maxWaitSeconds: 0.0);
        $second = new RateLimitedDriver($inner, $store, maxRequestsPerMinute: 1, maxWaitSeconds: 0.0);

        $first->chat($this->request());

        try {
            $second->chat($this->request());
            $this->fail('expected the shared quota to already be exhausted');
        } catch (RuntimeException) {
        }

        $this->assertSame(1, $inner->callCount);
    }
}
