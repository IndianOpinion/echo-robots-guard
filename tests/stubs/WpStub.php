<?php

declare(strict_types=1);

/**
 * State behind the WordPress function stubs. Tests reset it in setUp().
 */
final class WpStub
{
    /** @var array<string, array<int, list<array{0: callable, 1: int}>>> */
    public static array $hooks = [];

    /** @var array<int, array<string, mixed>> */
    public static array $options = [];

    /** @var array<int, array<string, bool>> */
    public static array $autoload = [];

    /** @var array<string, mixed> */
    public static array $siteOptions = [];

    public static bool $multisite = false;

    public static int $blog = 1;

    /** @var list<int> */
    public static array $blogStack = [];

    /** @var list<int> */
    public static array $sites = [1];

    public static bool $objectCache = true;

    /** @var array<string, array<string, mixed>> */
    public static array $cache = [];

    /** @var list<string> */
    public static array $globalGroups = [];

    /**
     * URL => list of responses, consumed in order; the last one repeats.
     * A response is ['status' => int, 'body' => string, 'headers' => array]
     * or a WP_Error.
     *
     * @var array<string, list<array<string, mixed>|WP_Error>>
     */
    public static array $http = [];

    /** @var list<array{url: string, args: array<string, mixed>, safe: bool}> */
    public static array $requests = [];

    /** @var list<string> Hosts that wp_safe_remote_get() refuses, as WordPress does for internal addresses. */
    public static array $unsafeHosts = [];

    /** @var list<string> */
    public static array $caps = ['manage_options', 'manage_network_options'];

    public static bool $admin = false;

    /** @var array<string, callable> */
    public static array $shortcodes = [];

    /** @var array<string, int> */
    public static array $scheduled = [];

    /** @var list<array{0: string, 1: string}> */
    public static array $menuPages = [];

    public static string $blogname = 'Daily Example';

    public static function reset(): void
    {
        self::$hooks = [];
        self::$options = [];
        self::$autoload = [];
        self::$siteOptions = [];
        self::$multisite = false;
        self::$blog = 1;
        self::$blogStack = [];
        self::$sites = [1];
        self::$objectCache = true;
        self::$cache = [];
        self::$globalGroups = [];
        self::$http = [];
        self::$requests = [];
        self::$unsafeHosts = [];
        self::$caps = ['manage_options', 'manage_network_options'];
        self::$admin = false;
        self::$shortcodes = [];
        self::$scheduled = [];
        self::$menuPages = [];
        self::$blogname = 'Daily Example';
        $_POST = [];
        $_REQUEST = [];
        $GLOBALS['wpdb'] = new FakeWpdb();
        WP_CLI::reset();
    }

    /**
     * @param array<string, string> $headers
     */
    public static function respond(string $url, int $status, string $body = '', array $headers = []): void
    {
        self::$http[$url][] = ['status' => $status, 'body' => $body, 'headers' => $headers];
    }

    public static function fail(string $url, string $message): void
    {
        self::$http[$url][] = new WP_Error('http_request_failed', $message);
    }

    /**
     * @return list<string>
     */
    public static function requestedUrls(): array
    {
        return array_map(static fn (array $r): string => $r['url'], self::$requests);
    }
}

final class WpDieException extends RuntimeException
{
}

final class WpRedirectException extends RuntimeException
{
}
