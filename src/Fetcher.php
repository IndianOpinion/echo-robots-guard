<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard;

use IndianOpinion\EchoRobotsGuard\Robots\Target;

/**
 * Fetches robots.txt through the WordPress HTTP API.
 *
 * Redirects are followed here, not by the HTTP API, so the plugin can count
 * them (H5, H6), refuse non-HTTP schemes (H7) and apply the unsafe-address
 * check to every hop (H14).
 */
class Fetcher
{
    public const MAX_REDIRECTS = 5;

    public function __construct(private Settings $settings)
    {
    }

    public function fetch(Target $target, ?string $token = null): FetchResult
    {
        $url = $target->robotsUrl();
        $current = $target;
        $args = [
            'timeout' => $this->settings->timeout(),
            'redirection' => 0,
            'user-agent' => $this->settings->userAgent($token),
            'headers' => ['Accept' => 'text/plain, */*;q=0.5'],
            'cookies' => [],
            'sslverify' => true,
            'limit_response_size' => $this->settings->maxBytes() + 1,
        ];

        for ($redirects = 0;; $redirects++) {
            $internalAllowed = in_array($current->host, $this->settings->allowedInternalHosts(), true);
            if (!$internalAllowed && !self::isSafeHost($current)) {
                return FetchResult::unreachable(null, 'unsafe', $redirects);
            }

            $response = $internalAllowed ? wp_remote_get($url, $args) : wp_safe_remote_get($url, $args);
            if (is_wp_error($response)) {
                return FetchResult::unreachable(null, self::classify($response->get_error_message()), $redirects);
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status >= 200 && $status < 300) {
                return FetchResult::ok($status, (string) wp_remote_retrieve_body($response), $redirects);
            }
            if ($status >= 300 && $status < 400) {
                $location = wp_remote_retrieve_header($response, 'location');
                if (is_array($location)) {
                    $location = (string) end($location);
                }
                if ($location === '') {
                    return FetchResult::unreachable($status, 'redirect', $redirects);
                }
                if ($redirects >= self::MAX_REDIRECTS) {
                    // RFC 9309 2.3.1.2: past the limit, MAY assume unavailable (H6).
                    return FetchResult::unavailable($status, $redirects, 'redirect_limit');
                }
                $url = self::resolve($url, $location);
                $next = Target::fromUrl($url);
                if ($next === null) {
                    return FetchResult::unreachable($status, 'redirect_scheme', $redirects + 1);
                }
                $current = $next;
                continue;
            }
            if ($status === 429) {
                // Read as "come back later", not "no file" (H9, decision 4).
                return FetchResult::unreachable($status, '429', $redirects);
            }
            if ($status >= 400 && $status < 500) {
                return FetchResult::unavailable($status, $redirects);
            }
            if ($status >= 500 && $status < 600) {
                return FetchResult::unreachable($status, '5xx', $redirects);
            }

            return FetchResult::unreachable($status, 'status', $redirects);
        }
    }

    /**
     * Refuses IP literals in private, loopback, link-local and reserved
     * ranges, and "localhost" (H14). Host names that resolve to such
     * addresses are refused by wp_safe_remote_get() itself.
     */
    public static function isSafeHost(Target $target): bool
    {
        if ($target->host === 'localhost' || str_ends_with($target->host, '.localhost')) {
            return false;
        }
        $ip = $target->hostIp();
        if ($ip === null) {
            return true;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        // Link-local and unique-local ranges, for PHP builds whose flags miss them.
        $lower = strtolower($ip);

        return !str_starts_with($ip, '169.254.')
            && !str_starts_with($lower, 'fe80:')
            && !str_starts_with($lower, 'fc')
            && !str_starts_with($lower, 'fd')
            && $lower !== '::1';
    }

    /**
     * Maps an HTTP API error message to a fetch error class.
     */
    public static function classify(string $message): string
    {
        $m = strtolower($message);
        $map = [
            'timeout' => ['timed out', 'curl error 28', 'timeout'],
            'dns' => ['resolve host', 'curl error 6:', 'name or service not known', 'getaddrinfo'],
            'tls' => ['ssl', 'certificate', 'tls', 'curl error 35', 'curl error 60'],
            'refused' => ['refused', 'curl error 7:'],
            'unsafe' => ['valid url was not provided'],
        ];
        foreach ($map as $class => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($m, $needle)) {
                    return $class;
                }
            }
        }

        return 'network';
    }

    /**
     * Resolves a Location header against the URL that sent it.
     */
    public static function resolve(string $base, string $location): string
    {
        $location = trim($location);
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $location;
        }
        $scheme = $parts['scheme'];
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }
        $authority = $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '/')) {
            return $authority . $location;
        }
        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $authority . ($dir === '' ? '/' : $dir) . $location;
    }
}
