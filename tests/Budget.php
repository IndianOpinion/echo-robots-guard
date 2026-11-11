<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Tests;

/**
 * Timing budgets from PRD section 9 are enforced as written in CI (where the
 * CI environment variable is set) or when ERG_STRICT_TIMING=1. On a
 * developer machine they are tripled, because laptops and shared boxes vary
 * far more than CI runners do.
 */
final class Budget
{
    public static function factor(): float
    {
        $strict = getenv('CI') === 'true' || getenv('ERG_STRICT_TIMING') === '1';

        return $strict ? 1.0 : 3.0;
    }
}
