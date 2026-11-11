<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard;

use IndianOpinion\EchoRobotsGuard\Store\TableStore;

/**
 * Removes everything the plugin created, on every site of a network (S6).
 */
final class Uninstaller
{
    public static function run(): void
    {
        $sites = [0];
        if (is_multisite()) {
            $sites = get_sites(['fields' => 'ids', 'number' => 0]);
        }
        foreach ($sites as $site) {
            $switched = false;
            if ($site !== 0) {
                switch_to_blog((int) $site);
                $switched = true;
            }
            delete_option(Settings::OPTION);
            delete_option(Recorder::COUNTERS);
            delete_option(Recorder::LOG);
            if ($switched) {
                restore_current_blog();
            }
        }

        if (function_exists('wp_cache_supports') && wp_cache_supports('flush_group')) {
            wp_cache_flush_group(Cache::GROUP);
        }
        wp_clear_scheduled_hook(Plugin::PURGE_EVENT);
        TableStore::drop();
    }
}
