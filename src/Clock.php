<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard;

/**
 * Time source. Tests replace it to move past expiry without sleeping.
 */
class Clock
{
    public function now(): int
    {
        return time();
    }
}
