<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Tests\Auth;

use CleatSquad\LlmRouter\Auth\ApiKeyPool;
use PHPUnit\Framework\TestCase;

final class ApiKeyPoolTest extends TestCase
{
    public function testSingleStringKey(): void
    {
        $pool = new ApiKeyPool('key-123');

        $this->assertFalse($pool->isEmpty());
        $this->assertCount(1, $pool);
        $this->assertSame('key-123', $pool->next());
        $this->assertSame('key-123', $pool->next());
    }

    public function testArrayOfKeysRotatesRoundRobin(): void
    {
        $pool = new ApiKeyPool(['k1', 'k2', 'k3']);

        $this->assertFalse($pool->isEmpty());
        $this->assertCount(3, $pool);
        $this->assertSame('k1', $pool->next());
        $this->assertSame('k2', $pool->next());
        $this->assertSame('k3', $pool->next());
        $this->assertSame('k1', $pool->next());
        $this->assertSame('k2', $pool->next());
    }

    public function testCommaSeparatedStringRotates(): void
    {
        $pool = new ApiKeyPool('k1, k2, k3');

        $this->assertFalse($pool->isEmpty());
        $this->assertCount(3, $pool);
        $this->assertSame('k1', $pool->next());
        $this->assertSame('k2', $pool->next());
        $this->assertSame('k3', $pool->next());
        $this->assertSame('k1', $pool->next());
    }

    public function testEmptyStringAndEmptyArrayProduceEmptyPool(): void
    {
        $pool1 = new ApiKeyPool('');
        $pool2 = new ApiKeyPool([]);
        $pool3 = new ApiKeyPool(['', '  ', '']);

        $this->assertTrue($pool1->isEmpty());
        $this->assertCount(0, $pool1);
        $this->assertSame('', $pool1->next());

        $this->assertTrue($pool2->isEmpty());
        $this->assertCount(0, $pool2);
        $this->assertSame('', $pool2->next());

        $this->assertTrue($pool3->isEmpty());
        $this->assertCount(0, $pool3);
        $this->assertSame('', $pool3->next());
    }

    public function testFiltersWhitespaceAndNonStrings(): void
    {
        $pool = new ApiKeyPool([' k1 ', '', 'k2', '   ', 'k3 ']);

        $this->assertCount(3, $pool);
        $this->assertSame(['k1', 'k2', 'k3'], $pool->all());
    }
}
