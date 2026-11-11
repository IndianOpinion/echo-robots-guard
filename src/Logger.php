<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard;

/**
 * Writes the few lines this plugin sends to the PHP error log. Callers make
 * sure it is never one line per item (one per internal error, one per origin
 * per day for fetch errors, one per fetch for parse warnings).
 */
class Logger
{
    /** @var callable(string): mixed */
    private $sink;

    /**
     * @param (callable(string): mixed)|null $sink
     */
    public function __construct(?callable $sink = null)
    {
        $this->sink = $sink ?? static fn (string $line): bool => error_log($line);
    }

    public function error(string $message): void
    {
        ($this->sink)('[echo-robots-guard] ' . $message);
    }
}
