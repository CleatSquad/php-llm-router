<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Contract\Exception;

/**
 * Marks a failure that belongs to the provider, not to the plan: a rate limit,
 * a timeout, a 5xx. The instruction was sound; the other end could not honour
 * it now. These are the failures a fail-over exists for, and what a custom
 * shouldFailover() can discriminate on.
 *
 * Not implementing it does not make a failure unretryable — a bare
 * RuntimeException still fails over by default. Only RoutingFailureInterface
 * changes that.
 *
 * @see RoutingFailureInterface for the other half of the split.
 */
interface ExecutionFailureInterface extends \Throwable
{
}
