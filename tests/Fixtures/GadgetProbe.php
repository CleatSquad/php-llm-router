<?php

declare(strict_types=1);

namespace LlmRouter\Tests\Fixtures;

/**
 * Stand-in for a gadget class: any type whose mere instantiation during
 * deserialization is already the damage, because __wakeup()/__destruct() run
 * before any caller gets a chance to type-check the result.
 *
 * Tests assert that decoding a hostile store payload never flips $instantiated,
 * which is a stronger claim than "the decoded value had the wrong type".
 */
final class GadgetProbe
{
    public static bool $instantiated = false;

    public string $marker = 'inert';

    public function __construct()
    {
        self::$instantiated = true;
    }

    public function __wakeup(): void
    {
        self::$instantiated = true;
    }

    public static function reset(): void
    {
        self::$instantiated = false;
    }
}
