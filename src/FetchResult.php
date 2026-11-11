<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard;

/**
 * The outcome of one robots.txt fetch (RFC 9309 2.3.1).
 */
final class FetchResult
{
    public function __construct(
        public readonly string $outcome,
        public readonly ?int $status,
        public readonly ?string $errorClass,
        public readonly string $body = '',
        public readonly int $redirects = 0
    ) {
    }

    public static function ok(int $status, string $body, int $redirects): self
    {
        return new self(CacheEntry::OK, $status, null, $body, $redirects);
    }

    public static function unavailable(?int $status, int $redirects, ?string $errorClass = null): self
    {
        return new self(CacheEntry::UNAVAILABLE, $status, $errorClass, '', $redirects);
    }

    public static function unreachable(?int $status, string $errorClass, int $redirects = 0): self
    {
        return new self(CacheEntry::UNREACHABLE, $status, $errorClass, '', $redirects);
    }
}
