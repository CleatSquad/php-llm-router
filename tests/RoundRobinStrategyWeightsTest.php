<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests;

use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Routing\RoundRobinStrategy;
use CleatSquad\LlmRouter\Tests\Fixtures\FakeDriver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Invariant protected: weighted rotation stays deterministic and total —
 *   every configured driver keeps getting turns, and a degenerate weight never
 *   removes one from the rotation or divides by zero.
 * Bug covered: none open — pins the algorithm, which must not be "fixed" into
 *   a random draw or into treating weight 0 as an exclusion.
 * Type: characterisation + edge cases.
 */
final class RoundRobinStrategyWeightsTest extends TestCase
{
    private function request(): LLMRequest
    {
        return new LLMRequest(messages: [['role' => 'user', 'content' => 'hi']]);
    }

    /**
     * @param array<string, int> $weights
     * @param FakeDriver[] $drivers
     * @return array<string, int>
     */
    private function distribution(array $weights, array $drivers, int $draws): array
    {
        $strategy = new RoundRobinStrategy($weights);
        $counts = array_fill_keys(array_map(static fn (FakeDriver $d): string => $d->getId(), $drivers), 0);

        for ($i = 0; $i < $draws; $i++) {
            $counts[$strategy->select($this->request(), $drivers)->getId()]++;
        }

        return $counts;
    }

    public function testAZeroWeightStillGetsTurnsRatherThanBeingExcluded(): void
    {
        $drivers = [new FakeDriver('a'), new FakeDriver('b')];

        $counts = $this->distribution(['a' => 0, 'b' => 1], $drivers, 20);

        // Weight 0 is clamped to 1, not treated as an exclusion: dropping a
        // configured driver from the rotation is a decision for the caller's
        // driver list, not for a weight typo.
        $this->assertSame(10, $counts['a']);
        $this->assertSame(10, $counts['b']);
    }

    public function testANegativeWeightIsClampedInsteadOfCorruptingTheSequence(): void
    {
        $drivers = [new FakeDriver('a'), new FakeDriver('b')];

        $counts = $this->distribution(['a' => -5, 'b' => 1], $drivers, 20);

        $this->assertSame(10, $counts['a']);
        $this->assertSame(10, $counts['b']);
    }

    public function testAWeightForAnUnknownDriverIsIgnored(): void
    {
        $drivers = [new FakeDriver('a'), new FakeDriver('b')];

        $counts = $this->distribution(['ghost' => 99], $drivers, 10);

        $this->assertSame(5, $counts['a']);
        $this->assertSame(5, $counts['b']);
    }

    public function testHeavilySkewedWeightsStillLeaveTheLightDriverInTheRotation(): void
    {
        $drivers = [new FakeDriver('heavy'), new FakeDriver('light')];

        $counts = $this->distribution(['heavy' => 100, 'light' => 1], $drivers, 202);

        $this->assertSame(200, $counts['heavy']);
        $this->assertSame(2, $counts['light'], 'starvation would be a bug, not a consequence of the weights');
    }

    public function testTheDistributionMatchesTheWeightsOverManyDraws(): void
    {
        $drivers = [new FakeDriver('a'), new FakeDriver('b'), new FakeDriver('c')];

        $counts = $this->distribution(['a' => 3, 'b' => 2, 'c' => 1], $drivers, 6_000);

        $this->assertSame(3_000, $counts['a']);
        $this->assertSame(2_000, $counts['b']);
        $this->assertSame(1_000, $counts['c']);
    }

    public function testRotationIsDeterministicSoTwoStrategiesAgreeDrawForDraw(): void
    {
        $drivers = [new FakeDriver('a'), new FakeDriver('b'), new FakeDriver('c')];
        $first = new RoundRobinStrategy(['a' => 2]);
        $second = new RoundRobinStrategy(['a' => 2]);

        for ($i = 0; $i < 24; $i++) {
            $this->assertSame(
                $first->select($this->request(), $drivers)->getId(),
                $second->select($this->request(), $drivers)->getId(),
                "draw $i diverged — rotation must not depend on chance"
            );
        }
    }

    public function testASingleDriverIsSelectedEveryTimeWhateverItsWeight(): void
    {
        $drivers = [new FakeDriver('only')];

        $this->assertSame(['only' => 7], $this->distribution(['only' => 4], $drivers, 7));
    }

    public function testAnEmptyDriverListIsRejectedRatherThanSilentlyReturningNothing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No LLM drivers provided');

        (new RoundRobinStrategy(['a' => 2]))->select($this->request(), []);
    }

    public function testWeightsDoNotResurrectAnUnavailableDriver(): void
    {
        $drivers = [new FakeDriver('down', available: false), new FakeDriver('up')];

        $counts = $this->distribution(['down' => 50], $drivers, 10);

        $this->assertSame(0, $counts['down']);
        $this->assertSame(10, $counts['up']);
    }
}
