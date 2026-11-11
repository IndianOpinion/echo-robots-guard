<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard;

use IndianOpinion\EchoRobotsGuard\Admin\AdminPage;
use IndianOpinion\EchoRobotsGuard\Cli\Command;
use IndianOpinion\EchoRobotsGuard\Store\EntryStore;
use IndianOpinion\EchoRobotsGuard\Store\TableStore;

/**
 * Wires the pieces together and registers the WordPress hooks. One instance
 * per request.
 */
final class Plugin
{
    public const VERSION = '0.1.0';

    public const PURGE_EVENT = 'echo_robots_guard_purge';

    private static ?self $instance = null;

    public readonly Settings $settings;

    public readonly Recorder $recorder;

    public readonly Cache $cache;

    public readonly Fetcher $fetcher;

    public readonly Guard $guard;

    public readonly EchoIntegration $echo;

    public readonly Statement $statement;

    public readonly AdminPage $admin;

    public function __construct(
        public readonly Clock $clock = new Clock(),
        ?EntryStore $store = null,
        public readonly Logger $logger = new Logger(),
        ?Fetcher $fetcher = null,
        ?Robots\Parser $parser = null
    ) {
        $this->settings = new Settings();
        $this->recorder = new Recorder($clock);
        $this->cache = new Cache($store ?? new TableStore(), $clock);
        $this->fetcher = $fetcher ?? new Fetcher($this->settings);
        $this->guard = new Guard(
            $this->settings,
            $this->cache,
            $this->fetcher,
            $this->recorder,
            $this->logger,
            $clock,
            $parser
        );
        $this->echo = new EchoIntegration($this->settings, $this->guard, $this->recorder, $this->logger);
        $this->statement = new Statement($this->settings);
        $this->admin = new AdminPage($this->settings, $this->recorder, $this->cache, $clock);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Replaces the shared instance. For tests and for code that needs a
     * custom store or clock; pass null to start fresh.
     */
    public static function setInstance(?self $plugin): void
    {
        self::$instance = $plugin;
    }

    public static function isLoaded(): bool
    {
        return self::$instance !== null;
    }

    /**
     * Called once from the main plugin file.
     */
    public function boot(): void
    {
        if (function_exists('wp_cache_add_global_groups')) {
            wp_cache_add_global_groups([Cache::GROUP]);
        }

        $this->echo->register();

        add_filter('echo_robots_guard_may_fetch', [$this, 'mayFetchFilter'], 10, 3);
        add_filter('echo_robots_guard_counters', [$this, 'countersFilter'], 1, 1);
        add_shortcode(Statement::SHORTCODE, fn (): string => $this->statement->html());
        add_action('shutdown', [$this->recorder, 'flush']);
        add_action(self::PURGE_EVENT, [$this, 'purge']);
        add_action('init', [$this, 'init']);

        if (is_admin()) {
            $this->admin->register();
        }
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::add_command(
                'echo-robots-guard',
                new Command($this->settings, $this->guard, $this->cache, $this->recorder)
            );
        }
    }

    public function init(): void
    {
        load_plugin_textdomain('echo-robots-guard', false, dirname(plugin_basename(ECHO_ROBOTS_GUARD_FILE)) . '/languages');
        if (!wp_next_scheduled(self::PURGE_EVENT)) {
            wp_schedule_event($this->clock->now() + 3600, 'daily', self::PURGE_EVENT);
        }
    }

    /**
     * Daily: delete cache rows that are expired and hold no usable stale copy.
     */
    public function purge(): void
    {
        $this->cache->purge(Settings::MAX_STALE_WINDOW);
    }

    /**
     * The "echo_robots_guard_may_fetch" filter (A3): true, false, or null
     * when the URL is not checked.
     */
    public function mayFetchFilter(mixed $default, mixed $url = null, mixed $token = null): mixed
    {
        if (!is_string($url)) {
            return $default;
        }
        $decision = $this->mayFetch($url, is_string($token) ? $token : null);

        return $decision->allowed;
    }

    /**
     * @param mixed $counters
     * @return array<string, mixed>
     */
    public function countersFilter(mixed $counters): array
    {
        return $this->recorder->counters();
    }

    /**
     * The public API behind may_fetch() (A1, A2, A4). Never throws for a bad
     * URL; throws InvalidTokenException for a bad token. Any other fault is
     * reported as an internal error and allows the fetch (F4).
     */
    public function mayFetch(string $url, ?string $token = null): Decision
    {
        if ($token !== null && !Settings::isValidToken($token)) {
            throw Exception\InvalidTokenException::for($token);
        }
        try {
            return $this->guard->check($url, $token, 'api');
        } catch (\Throwable $e) {
            $this->recorder->count('api', 'internal_errors');
            $this->logger->error(sprintf('internal error in may_fetch: %s: %s', get_class($e), $e->getMessage()));

            return new Decision($url, true, Decision::INTERNAL_ERROR, null, null, 'none', $token);
        }
    }

    /**
     * Activation: create the network table. Settings are created lazily on
     * first read on every site (S4), so network activation needs no loop.
     */
    public static function activate(): void
    {
        TableStore::install();
    }

    /**
     * Deactivation removes no data; it only unschedules the purge event.
     */
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::PURGE_EVENT);
    }
}
