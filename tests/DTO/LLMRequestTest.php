<?php

declare(strict_types=1);

namespace Concio\LlmRouter\Tests\DTO;

use Concio\LlmRouter\DTO\LLMRequest;
use PHPUnit\Framework\TestCase;

final class LLMRequestTest extends TestCase
{
    public function testEstimateInputTokensOnPlainTextMessages(): void
    {
        $request = new LLMRequest(messages: [
            ['role' => 'user', 'content' => str_repeat('a', 40)],
        ]);

        // ~4 chars per token
        $this->assertSame(10, $request->estimateInputTokens());
    }

    public function testEstimateInputTokensOnMultimodalMessages(): void
    {
        $request = new LLMRequest(messages: [
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => str_repeat('a', 40)],
                ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/x.png']],
            ]],
        ]);

        // 40 chars of text (10 tokens) + 1000-char image approximation (250 tokens)
        $this->assertSame(260, $request->estimateInputTokens());
    }

    public function testEstimateInputTokensOnEmptyMessages(): void
    {
        $request = new LLMRequest(messages: []);

        $this->assertSame(0, $request->estimateInputTokens());
    }
}
