<?php

declare(strict_types=1);

namespace CleatSquad\LlmRouter\Contract\Exception;

/**
 * Marks a failure that belongs to the routing plan, not to the provider: the
 * candidate was handed an impossible instruction, so no amount of retrying or
 * switching providers changes the answer. Means stop and surface this; the fix
 * is in whatever produced the candidate.
 *
 * An interface rather than a base class, so the exceptions carrying it keep
 * extending RuntimeException and existing catch sites are unaffected.
 *
 * @see ExecutionFailureInterface for the other half of the split.
 */
interface RoutingFailureInterface extends \Throwable
{
}
