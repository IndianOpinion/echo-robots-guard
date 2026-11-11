<?php

declare(strict_types=1);

// Constants WordPress defines at runtime, for static analysis only.
define('ECHO_ROBOTS_GUARD_FILE', __DIR__ . '/../echo-robots-guard.php');
if (!defined('WPMU_PLUGIN_DIR')) {
    define('WPMU_PLUGIN_DIR', __DIR__);
}
