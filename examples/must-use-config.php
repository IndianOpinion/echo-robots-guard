<?php

/**
 * Fixing settings in code for a must-use install. Copy the lines you need
 * into wp-config.php (before "That's all, stop editing!") or into an
 * mu-plugin that loads before the guard. The admin page then shows these
 * settings as read-only.
 *
 * @package IndianOpinion\EchoRobotsGuard
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Required for a stable public identity: publishers write rules for it.
define('ECHO_ROBOTS_GUARD_TOKEN', 'ExampleNewsBot');

// "enforce" skips disallowed items, "shadow" only logs them, "off" does nothing.
define('ECHO_ROBOTS_GUARD_MODE', 'shadow');

// "closed" (RFC 9309) or "open".
define('ECHO_ROBOTS_GUARD_FAIL_POLICY', 'closed');

// Seconds.
define('ECHO_ROBOTS_GUARD_TTL', 86400);
define('ECHO_ROBOTS_GUARD_ERROR_TTL', 3600);
define('ECHO_ROBOTS_GUARD_TIMEOUT', 5);

// Hosts whose robots.txt speaks for a redirect service, not the publisher.
define('ECHO_ROBOTS_GUARD_SKIP_HOSTS', 'news.google.com');

define('ECHO_ROBOTS_GUARD_BOT_PAGE_URL', 'https://www.example.com/bot/');
define('ECHO_ROBOTS_GUARD_CONTACT_EMAIL', 'bots@' . wp_parse_url(home_url(), PHP_URL_HOST));

// Filters work too, and run after constants.
add_filter('echo_robots_guard_max_fetches_per_request', static fn (): int => 40);
add_action('echo_robots_guard_blocked', static function (string $url, $rule, string $mode): void {
    error_log(sprintf('[robots] %s %s', $mode === 'enforce' ? 'skipped' : 'would skip', $url));
}, 10, 3);
