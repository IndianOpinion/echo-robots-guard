<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Exception;

/**
 * Base class for every error this plugin raises. Callers inside WordPress
 * hooks catch it (and anything else) at the boundary, so a fault here never
 * stops an import run.
 */
class RobotsGuardException extends \RuntimeException
{
}
