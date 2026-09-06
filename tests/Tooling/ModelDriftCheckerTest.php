<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Tooling;

use CleatSquad\LlmRouter\Tooling\ModelDriftChecker;
use PHPUnit\Framework\TestCase;

final class ModelDriftCheckerTest extends TestCase
{
    private ModelDriftChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new ModelDriftChecker();
    }

    public function testIsMovingAlias(): void
    {
        self::assertTrue($this->checker->isMovingAlias('gemini-flash-lite-latest'));
        self::assertTrue($this->checker->isMovingAlias('mistral-medium-latest'));
        self::assertFalse($this->checker->isMovingAlias('gemini-2.5-flash'));
        self::assertFalse($this->checker->isMovingAlias('claude-sonnet-5'));
    }

    public function testIsChatModelFiltersNonChatAndDeclined(): void
    {
        // Non-chat markers
        self::assertFalse($this->checker->isChatModel('text-embedding-3-small'));
        self::assertFalse($this->checker->isChatModel('whisper-1'));
        self::assertFalse($this->checker->isChatModel('dall-e-3'));
        self::assertFalse($this->checker->isChatModel('gemini-2.0-flash-preview'));

        // Declined generations
        self::assertFalse($this->checker->isChatModel('gpt-3.5-turbo'));
        self::assertFalse($this->checker->isChatModel('gpt-4-0613'));
        self::assertFalse($this->checker->isChatModel('gemma-2-9b-it'));
        self::assertFalse($this->checker->isChatModel('groq/compound'));

        // Valid chat models
        self::assertTrue($this->checker->isChatModel('gpt-5'));
        self::assertTrue($this->checker->isChatModel('claude-sonnet-5'));
        self::assertTrue($this->checker->isChatModel('deepseek-v4-flash'));
    }

    public function testIsServedMatchesExactAndDatedSnapshots(): void
    {
        $live = ['claude-haiku-4-5-20251001', 'gpt-5'];

        self::assertTrue($this->checker->isServed('claude-haiku-4-5', $live));
        self::assertTrue($this->checker->isServed('gpt-5', $live));
        self::assertFalse($this->checker->isServed('claude-opus-5', $live));
    }

    public function testIsSnapshotOfKnownMatchesStemAndSnapshots(): void
    {
        $shipped = ['o1', 'mistral-medium-latest'];

        self::assertTrue($this->checker->isSnapshotOfKnown('o1-2024-12-17', $shipped));
        self::assertTrue($this->checker->isSnapshotOfKnown('mistral-medium-2505', $shipped));
        self::assertFalse($this->checker->isSnapshotOfKnown('o3-mini', $shipped));
        self::assertFalse($this->checker->isSnapshotOfKnown('mistral-small-2501', $shipped));
    }

    public function testCompareReturnsRetiredAndMissingModels(): void
    {
        $shipped = [
            'claude-haiku-4-5', // served via snapshot
            'claude-sonnet-4',   // not served anywhere (retired)
            'claude-opus-5',    // served exact
        ];

        $live = [
            'claude-haiku-4-5-20251001',
            'claude-opus-5',
            'claude-opus-5-20260101', // snapshot of known (should not be missing)
            'claude-sonnet-6',        // new chat model (missing)
            'claude-embedding-v1',    // non-chat (filtered out)
            'claude-sonnet-6-latest', // moving alias (filtered out)
        ];

        $result = $this->checker->compare($shipped, $live);

        self::assertSame(['claude-sonnet-4'], $result['retired']);
        self::assertSame(['claude-sonnet-6'], $result['missing']);
    }
}
