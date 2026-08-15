<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Unit\Ranker;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\Candidate;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;
use CleatSquad\LlmRouter\Ranker\PriorityRanker;
use PHPUnit\Framework\TestCase;

final class RankersTest extends TestCase
{
    public function testPriorityRankerScoresCorrectly(): void
    {
        $driver = $this->createMock(LLMDriverInterface::class);
        $driver->method('getId')->willReturn('claude');

        $candidate = new Candidate('claude', 'Claude', $driver);
        $eval = new CandidateEvaluation($candidate);
        $request = new LLMRequest(messages: []);

        $ranker = new PriorityRanker(priorities: ['claude' => 10]);
        $score = $ranker->score($eval, $request);

        $this->assertSame(10.0, $score->value);
        $this->assertSame('PriorityRanker', $score->ranker);
    }
}
