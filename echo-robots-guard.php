<?php

/**
 * Plugin Name:       Echo Robots Guard
 * Plugin URI:        https://github.com/IndianOpinion/echo-robots-guard
 * Description:       Checks the publisher's robots.txt (RFC 9309) before the Echo RSS importer downloads an article page, and skips pages that are disallowed for your product token.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       echo-robots-guard
 * Domain Path:       /languages
 * Network:           false
 *
 * @package IndianOpinion\EchoRobotsGuard
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (defined('ECHO_ROBOTS_GUARD_FILE')) {
    // Loaded twice (for example as a plugin and as a must-use plugin).
    return;
}

define('ECHO_ROBOTS_GUARD_FILE', __FILE__);

require_once __DIR__ . '/src/autoload.php';

register_activation_hook(__FILE__, [\IndianOpinion\EchoRobotsGuard\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\IndianOpinion\EchoRobotsGuard\Plugin::class, 'deactivate']);

\IndianOpinion\EchoRobotsGuard\Plugin::instance()->boot();
