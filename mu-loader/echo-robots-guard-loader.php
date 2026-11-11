<?php

/**
 * Plugin Name: Echo Robots Guard (must-use loader)
 * Description: Loads Echo Robots Guard from wp-content/mu-plugins/echo-robots-guard/.
 * Version:     0.1.0
 * License:     MIT
 *
 * Copy this file to wp-content/mu-plugins/ and the plugin folder to
 * wp-content/mu-plugins/echo-robots-guard/. WordPress loads files in
 * mu-plugins/ but not folders, so this one line is the bridge.
 *
 * @package IndianOpinion\EchoRobotsGuard
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once WPMU_PLUGIN_DIR . '/echo-robots-guard/echo-robots-guard.php';
