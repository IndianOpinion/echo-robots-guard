<?php

/**
 * Asking "may I fetch this URL?" from any importer, not only Echo.
 *
 * Drop this into wp-content/mu-plugins/ (or run it with
 * `wp eval-file examples/another-importer.php`) and adapt the hook name to
 * your importer.
 *
 * @package IndianOpinion\EchoRobotsGuard
 */

declare(strict_types=1);

use IndianOpinion\EchoRobotsGuard\Decision;

use function IndianOpinion\EchoRobotsGuard\may_fetch;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Option 1: the filter. Works whether or not the plugin is active: you get
 * null when it is not, or when the URL is not checked.
 */
function my_importer_may_fetch(string $url): bool
{
    $answer = apply_filters('echo_robots_guard_may_fetch', null, $url);

    return $answer !== false;
}

/**
 * Option 2: the function, for the full answer with the reason and the rule.
 */
function my_importer_explain(string $url): string
{
    if (!function_exists('IndianOpinion\EchoRobotsGuard\may_fetch')) {
        return 'echo-robots-guard is not active';
    }
    $decision = may_fetch($url);
    if ($decision->reason === Decision::RULE) {
        return sprintf('%s: %s', $decision->allowed ? 'allowed' : 'disallowed', $decision->ruleText());
    }

    return sprintf('%s (%s)', var_export($decision->allowed, true), $decision->reason);
}

if (defined('WP_CLI') && WP_CLI) {
    foreach (['https://www.example.com/', 'https://www.example.com/private'] as $url) {
        WP_CLI::line($url . ' => ' . my_importer_explain($url));
    }
}
