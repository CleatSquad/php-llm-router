<?php

declare(strict_types=1);

namespace LlmRouter\Tests\Driver;

use LlmRouter\Contract\Driver\LLMDriverInterface;
use LlmRouter\Contract\RoutingStrategyInterface;
use LlmRouter\Driver\FailoverDriver;
use LlmRouter\DTO\LLMRequest;
use LlmRouter\Exception\AllDriversFailedException;
use LlmRouter\Exception\RateLimitException;
use LlmRouter\Tests\Fixtures\ControllableDriver;
use LlmRouter\Tests\Fixtures\PartialStreamDriver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Invariant protected: when every candidate fails, the caller gets each
 *   attempt as a live exception object — not a flattened message string — and
 *   streaming still refuses to fail over once a fragment has been emitted.
 * Bug covered: none open — pins the structure callers depend on to react
 *   selectively (e.g. "every cloud provider is rate limited" vs "one is down").
 * Type: characterisation.
 */
final class FailoverDriverStructuredFailureTest extends TestCase
{
    private function request(): LLMRequest
    {
        return new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]);
    }

    /**
     * @param LLMDriverInterface[] $order
     */
    private function strategyFor(array $order): RoutingStrategyInterface
    {
        return new class($order) implements RoutingStrategyInterface {
            /** @param LLMDriverInterface[] $order */
            public function __construct(private array $order) {}

            public function select(LLMRequest $request, array $drivers): LLMDriverInterface
            {
                foreach ($this->order as $preferred) {
                    foreach ($drivers as $driver) {
                        if ($driver->getId() === $preferred->getId()) {
                            return $driver;
                        }
                    }
                }

                throw new RuntimeException('no candidate left');
            }
        };
    }

    public function testEachAttemptKeepsItsOwnExceptionTypeAndTypedData(): void
    {
        $a = new ControllableDriver('a', [new RateLimitException('a is throttled', 30)]);
        $b = new ControllableDriver('b', [new RuntimeException('b is down')]);
        $router = new FailoverDriver($this->strategyFor([$a, $b]), [$a, $b]);

        try {
            $router->chat($this->request());
            $this->fail('expected AllDriversFailedException');
        } catch (AllDriversFailedException $e) {
            $failures = $e->getFailures();

            $this->assertCount(2, $failures);

            // The point of the structure: a caller can branch on the typed
            // retry delay without ever parsing a human-readable message.
            $first = $failures[0]['exception'];
            $this->assertInstanceOf(RateLimitException::class, $first);
            $this->assertSame(30, $first->getRetryAfterSeconds());

            $this->assertNotInstanceOf(RateLimitException::class, $failures[1]['exception']);
            $this->assertSame('b', $failures[1]['driverId']);
        }
    }

    public function testTheFailureListIsOrderedByAttemptAndCoversEveryCandidate(): void
    {
        $drivers = [];
        foreach (['a', 'b', 'c', 'd'] as $id) {
            $drivers[] = new ControllableDriver($id, [new RuntimeException("$id is down")]);
        }
        $router = new FailoverDriver($this->strategyFor($drivers), $drivers);

        try {
            $router->chat($this->request());
            $this->fail('expected AllDriversFailedException');
        } catch (AllDriversFailedException $e) {
            $this->assertSame(
                ['a', 'b', 'c', 'd'],
                array_column($e->getFailures(), 'driverId'),
                'every excluded driver must be recorded, in the order it was tried'
            );
        }

        foreach ($drivers as $driver) {
            $this->assertSame(1, $driver->callCount, 'each candidate is tried exactly once');
        }
    }

    public function testStreamingExhaustionAlsoReportsStructuredFailures(): void
    {
        $a = new ControllableDriver('a', [new RuntimeException('a is down')]);
        $b = new ControllableDriver('b', [new RuntimeException('b is down')]);
        $router = new FailoverDriver($this->strategyFor([$a, $b]), [$a, $b]);

        try {
            iterator_to_array($router->stream($this->request()));
            $this->fail('expected AllDriversFailedException');
        } catch (AllDriversFailedException $e) {
            $this->assertSame(['a', 'b'], array_column($e->getFailures(), 'driverId'));
        }
    }

    public function testAFailureAfterTheFirstFragmentPropagatesInsteadOfFailingOver(): void
    {
        $a = new PartialStreamDriver('a', ['Hel', 'lo'], new RuntimeException('a died mid-stream'));
        $b = new ControllableDriver('b');
        $router = new FailoverDriver($this->strategyFor([$a, $b]), [$a, $b]);

        $chunks = [];
        try {
            foreach ($router->stream($this->request()) as $chunk) {
                $chunks[] = $chunk;
            }
            $this->fail('expected the mid-stream failure to propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('a died mid-stream', $e->getMessage());
            $this->assertNotInstanceOf(AllDriversFailedException::class, $e);
        }

        // Switching providers here would replay the answer from the top on top
        // of what the user already sees.
        $this->assertSame(['Hel', 'lo'], $chunks);
        $this->assertSame(0, $b->callCount, 'no failover once output has been emitted');
    }

    public function testTheMessageStillSummarisesEveryFailureForLogs(): void
    {
        $a = new ControllableDriver('a', [new RuntimeException('a is down')]);
        $b = new ControllableDriver('b', [new RuntimeException('b is down')]);
        $router = new FailoverDriver($this->strategyFor([$a, $b]), [$a, $b]);

        try {
            $router->chat($this->request());
            $this->fail('expected AllDriversFailedException');
        } catch (AllDriversFailedException $e) {
            // The summary is a convenience for logs; getFailures() stays the
            // supported way to react programmatically.
            $this->assertStringContainsString('a: a is down', $e->getMessage());
            $this->assertStringContainsString('b: b is down', $e->getMessage());
        }
    }
}
