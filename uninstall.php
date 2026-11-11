<?php

/**
 * Removes the plugin's options, counters, log, cache table and schedule on
 * every site. Runs only when WordPress uninstalls the plugin.
 *
 * @package IndianOpinion\EchoRobotsGuard
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/src/autoload.php';

\IndianOpinion\EchoRobotsGuard\Uninstaller::run();
