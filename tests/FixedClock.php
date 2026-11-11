<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Tests;

use IndianOpinion\EchoRobotsGuard\Clock;

final class FixedClock extends Clock
{
    public function __construct(public int $time = 1767225600)
    {
    }

    public function now(): int
    {
        return $this->time;
    }

    public function advance(int $seconds): void
    {
        $this->time += $seconds;
    }
}
