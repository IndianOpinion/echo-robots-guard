<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Robots;

/**
 * An http(s) URL split into the parts robots.txt cares about: the origin
 * (scheme, host, port) whose robots.txt applies, and the normalised path plus
 * query that is matched. Pure PHP, no WordPress.
 */
final class Target
{
    private function __construct(
        public readonly string $scheme,
        public readonly string $host,
        public readonly ?int $port,
        public readonly string $path
    ) {
    }

    /**
     * Null when the value is not an absolute http or https URL with a host
     * (H15). Userinfo is dropped and never used (H3).
     */
    public static function fromUrl(string $url): ?self
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $host = self::normaliseHost($parts['host']);
        if ($host === null) {
            return null;
        }
        $port = $parts['port'] ?? null;
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }

        $path = $parts['path'] ?? '';
        if ($path === '') {
            $path = '/';
        }
        if (isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }

        return new self($scheme, $host, $port, Normalizer::path($path));
    }

    /**
     * Cache key: "scheme://host[:port]" (H1, H2, C1).
     */
    public function origin(): string
    {
        return $this->scheme . '://' . $this->host . ($this->port === null ? '' : ':' . $this->port);
    }

    public function robotsUrl(): string
    {
        return $this->origin() . '/robots.txt';
    }

    /**
     * True when the host is an IP literal (IPv4, or IPv6 in brackets).
     */
    public function hostIp(): ?string
    {
        $bare = trim($this->host, '[]');

        return filter_var($bare, FILTER_VALIDATE_IP) === false ? null : $bare;
    }

    private static function normaliseHost(string $host): ?string
    {
        $host = strtolower($host);
        if (str_starts_with($host, '[')) {
            return str_ends_with($host, ']') ? $host : null;
        }
        $host = rtrim($host, '.');
        if ($host === '') {
            return null;
        }
        if (preg_match('/[^\x00-\x7F]/', $host) === 1 && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = strtolower($ascii);
            }
        }
        if (preg_match('/[\s\/\\\\@?#]/', $host) === 1) {
            return null;
        }

        return $host;
    }
}
