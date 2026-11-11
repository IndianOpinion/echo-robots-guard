<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Exception;

/**
 * A product token outside RFC 9309 2.2.1: 1 to 64 of A-Z, a-z, "_" and "-".
 */
final class InvalidTokenException extends RobotsGuardException
{
    public static function for(string $token): self
    {
        return new self(sprintf('Invalid product token "%s": use 1 to 64 letters, "_" or "-".', $token));
    }
}
