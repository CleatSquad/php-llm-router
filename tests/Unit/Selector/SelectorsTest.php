<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Unit\Selector;

use CleatSquad\LlmRouter\Contract\Driver\LLMDriverInterface;
use CleatSquad\LlmRouter\DTO\LLMRequest;
use CleatSquad\LlmRouter\Engine\Candidate;
use CleatSquad\LlmRouter\Engine\CandidateEvaluation;
use CleatSquad\LlmRouter\Engine\RankScore;
use CleatSquad\LlmRouter\Selector\BestCandidateSelector;
use PHPUnit\Framework\TestCase;

final class SelectorsTest extends TestCase
{
    public function testBestCandidateSelectorOrdersByScoreDescending(): void
    {
        $d1 = $this->createMock(LLMDriverInterface::class);
        $d2 = $this->createMock(LLMDriverInterface::class);
        $c1 = new Candidate('c1', 'C1', $d1);
        $c2 = new Candidate('c2', 'C2', $d2);
        
        $e1 = new CandidateEvaluation($c1);
        $e1->score = new RankScore(0.5, 'Test');
        
        $e2 = new CandidateEvaluation($c2);
        $e2->score = new RankScore(0.9, 'Test');

        $selector = new BestCandidateSelector();
        $ordered = $selector->select([$e1, $e2], new LLMRequest(messages: []));

        $this->assertCount(2, $ordered);
        $this->assertSame($c2, $ordered[0]);
        $this->assertSame($c1, $ordered[1]);
    }
}
