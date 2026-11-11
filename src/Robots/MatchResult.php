<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Robots;

/**
 * The answer for one path: allowed or not, and the rule that decided it.
 */
final class MatchResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly ?Rule $rule,
        public readonly ?string $agent
    ) {
    }
}
