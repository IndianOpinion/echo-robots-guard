<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Admin;

use IndianOpinion\EchoRobotsGuard\Cache;
use IndianOpinion\EchoRobotsGuard\CacheEntry;
use IndianOpinion\EchoRobotsGuard\Clock;
use IndianOpinion\EchoRobotsGuard\Recorder;
use IndianOpinion\EchoRobotsGuard\Settings;

/**
 * Tools > Echo Robots Guard (U1 to U3).
 *
 * Every form posts to admin-post.php with a nonce; every handler checks the
 * nonce and the capability before it changes anything. Every value printed
 * is escaped.
 */
final class AdminPage
{
    public const SLUG = 'echo-robots-guard';

    public const SAVE_ACTION = 'echo_robots_guard_save';

    public const CLEAR_ACTION = 'echo_robots_guard_clear';

    public const LONG_UNREACHABLE = 604800;

    public function __construct(
        private Settings $settings,
        private Recorder $recorder,
        private Cache $cache,
        private Clock $clock
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addPage']);
        add_action('admin_post_' . self::SAVE_ACTION, function (): void {
            $this->redirect($this->handleSave($this->post()));
        });
        add_action('admin_post_' . self::CLEAR_ACTION, function (): void {
            $this->redirect($this->handleClear($this->post()));
        });
        add_action('admin_notices', [$this, 'globalNotice']);
    }

    public function addPage(): void
    {
        add_management_page(
            __('Echo Robots Guard', 'echo-robots-guard'),
            __('Echo Robots Guard', 'echo-robots-guard'),
            'manage_options',
            self::SLUG,
            [$this, 'render']
        );
    }

    public static function clearCapability(): string
    {
        return is_multisite() ? 'manage_network_options' : 'manage_options';
    }

    /**
     * @param array<mixed> $post Unslashed $_POST.
     */
    public function handleSave(array $post): string
    {
        check_admin_referer(self::SAVE_ACTION);
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to change these settings.', 'echo-robots-guard'), '', ['response' => 403]);
        }
        $input = [];
        $raw = $post['echo_robots_guard'] ?? null;
        if (is_array($raw)) {
            foreach ($raw as $key => $value) {
                if (is_string($key)) {
                    $input[$key] = $value;
                }
            }
        }
        $rejected = $this->settings->save($input);
        $args = ['page' => self::SLUG, 'updated' => '1'];
        if ($rejected !== []) {
            $args['rejected'] = implode(',', $rejected);
        }

        return add_query_arg($args, admin_url('tools.php'));
    }

    /**
     * @param array<mixed> $post Unslashed $_POST.
     */
    public function handleClear(array $post): string
    {
        check_admin_referer(self::CLEAR_ACTION);
        if (!current_user_can(self::clearCapability())) {
            wp_die(esc_html__('You are not allowed to clear the robots.txt cache.', 'echo-robots-guard'), '', ['response' => 403]);
        }
        $origin = isset($post['origin']) && is_string($post['origin']) ? trim($post['origin']) : '';
        if ($origin === '') {
            $this->cache->clear();
        } else {
            $this->cache->delete($origin);
        }

        return add_query_arg(['page' => self::SLUG, 'cleared' => '1'], admin_url('tools.php'));
    }

    /**
     * Notices for the page itself (section 11).
     *
     * @return list<array{type: string, text: string}>
     */
    public function notices(): array
    {
        $notices = [];
        if (!$this->settings->isConfirmed()) {
            $notices[] = ['type' => 'warning', 'text' => sprintf(
                /* translators: %s: product token */
                __('Please confirm the product token %s below, or change it. Publishers write robots.txt rules for this name, so keep it stable once published. On a site that already imports, consider shadow mode first to review what would be skipped.', 'echo-robots-guard'),
                $this->settings->token()
            )];
        }
        foreach ($this->settings->rejected() as $key) {
            $notices[] = ['type' => 'error', 'text' => sprintf(
                /* translators: %s: setting name */
                __('The value for "%s" is not valid; the default is used instead.', 'echo-robots-guard'),
                $key
            )];
        }
        if (in_array('ttl_over_24h', $this->settings->warnings(), true)) {
            $notices[] = ['type' => 'warning', 'text' => __('The cache TTL is above 24 hours. RFC 9309 2.4 says a crawler should not use a cached robots.txt for more than 24 hours.', 'echo-robots-guard')];
        }
        if ($this->settings->failsOpen()) {
            $notices[] = ['type' => 'warning', 'text' => __('Fail open is in use: unreachable robots.txt files are treated as allowing access. This is not RFC 9309 compliant, and the public statement says so.', 'echo-robots-guard')];
        }
        $last = $this->recorder->lastEchoCall();
        if ($this->settings->mode() !== 'off') {
            if ($last === null) {
                $notices[] = ['type' => 'info', 'text' => __('No Echo calls seen yet. The check runs when Echo imports an item.', 'echo-robots-guard')];
            } elseif ($this->clock->now() - $last > 7 * 86400) {
                $notices[] = ['type' => 'warning', 'text' => __('No Echo calls seen in the last 7 days. Is Echo still importing?', 'echo-robots-guard')];
            }
        }

        return $notices;
    }

    /**
     * Notices for the result of the last save or clear, read from the query
     * string of the redirect (T104). Only known setting names are shown.
     *
     * @param array<mixed> $query Unslashed $_GET.
     * @return list<array{type: string, text: string}>
     */
    public function resultNotices(array $query): array
    {
        $notices = [];
        if (($query['updated'] ?? '') === '1') {
            $notices[] = ['type' => 'success', 'text' => __('Settings saved.', 'echo-robots-guard')];
        }
        if (isset($query['rejected']) && is_string($query['rejected'])) {
            $known = array_keys(Settings::FILTERS);
            foreach (array_intersect(explode(',', $query['rejected']), $known) as $key) {
                $notices[] = ['type' => 'error', 'text' => sprintf(
                    /* translators: %s: setting name */
                    __('The value given for "%s" was not valid and was not saved; the previous value is kept.', 'echo-robots-guard'),
                    $key
                )];
            }
        }
        if (($query['cleared'] ?? '') === '1') {
            $notices[] = ['type' => 'success', 'text' => __('robots.txt cache cleared.', 'echo-robots-guard')];
        }

        return $notices;
    }

    public function globalNotice(): void
    {
        if (!current_user_can('manage_options') || $this->settings->isConfirmed()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen instanceof \WP_Screen && str_contains($screen->id, self::SLUG)) {
            return;
        }
        printf(
            '<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
            esc_html__('Echo Robots Guard is using a product token derived from the site title.', 'echo-robots-guard'),
            esc_url(add_query_arg(['page' => self::SLUG], admin_url('tools.php'))),
            esc_html__('Confirm or change the token.', 'echo-robots-guard')
        );
    }

    /**
     * Origins whose robots.txt has been unreachable for more than 7 days (F5).
     *
     * @return list<CacheEntry>
     */
    public function longUnreachable(): array
    {
        $now = $this->clock->now();
        $long = [];
        foreach ($this->cache->all() as $entry) {
            if (
                $entry->outcome === CacheEntry::UNREACHABLE
                && $entry->unreachableSince !== null
                && $now - $entry->unreachableSince > self::LONG_UNREACHABLE
            ) {
                $long[] = $entry;
            }
        }

        return $long;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to view this page.', 'echo-robots-guard'), '', ['response' => 403]);
        }

        echo '<div class="wrap"><h1>' . esc_html__('Echo Robots Guard', 'echo-robots-guard') . '</h1>';

        // Read-only display of the redirect after a save or clear; the
        // handlers checked the nonce before they redirected here.
        $query = wp_unslash($_GET); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $notices = array_merge($this->resultNotices($query), $this->notices());
        foreach ($notices as $notice) {
            printf(
                '<div class="notice notice-%s"><p>%s</p></div>',
                esc_attr($notice['type']),
                esc_html($notice['text'])
            );
        }

        $this->renderSettings();
        $this->renderCounters();
        $this->renderLog();
        $this->renderCache();

        echo '</div>';
    }

    private function renderSettings(): void
    {
        $s = $this->settings;
        $stored = $s->stored();
        echo '<h2>' . esc_html__('Settings', 'echo-robots-guard') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::SAVE_ACTION) . '">';
        wp_nonce_field(self::SAVE_ACTION);
        echo '<table class="form-table" role="presentation">';

        $this->select('mode', __('Mode', 'echo-robots-guard'), $s->mode(), [
            'enforce' => __('Enforce: skip disallowed items', 'echo-robots-guard'),
            'shadow' => __('Shadow: log only (trial setting for an existing site)', 'echo-robots-guard'),
            'off' => __('Off', 'echo-robots-guard'),
        ]);
        $this->text('token', __('Product token', 'echo-robots-guard'), $s->token(), __('Publishers write "User-agent: <token>" for this name. Letters, "_" and "-", up to 64.', 'echo-robots-guard'));
        $this->select('fail_policy', __('Unreachable robots.txt', 'echo-robots-guard'), $s->failPolicy(), [
            'closed' => __('Fail closed: treat as disallowed (RFC 9309)', 'echo-robots-guard'),
            'open' => __('Fail open: treat as allowed (not RFC 9309 compliant)', 'echo-robots-guard'),
        ]);
        $this->text('ttl', __('Cache TTL (seconds)', 'echo-robots-guard'), (string) $s->ttl(), __('300 to 604800. RFC 9309 suggests at most 86400.', 'echo-robots-guard'));
        $this->text('error_ttl', __('Cache TTL after an error (seconds)', 'echo-robots-guard'), (string) $s->errorTtl(), __('60 up to the cache TTL.', 'echo-robots-guard'));
        $this->text('stale_window', __('Stale copy window (seconds)', 'echo-robots-guard'), (string) $s->staleWindow(), __('0 (off) to 7776000.', 'echo-robots-guard'));
        $this->text('timeout', __('Fetch timeout (seconds)', 'echo-robots-guard'), (string) $s->timeout(), __('1 to 30.', 'echo-robots-guard'));
        $this->text('max_fetches', __('robots.txt fetches per request', 'echo-robots-guard'), (string) $s->maxFetches(), __('Items past the cap use a stale copy or the failure policy.', 'echo-robots-guard'));
        $this->text('bot_page_url', __('Bot page URL', 'echo-robots-guard'), (string) ($stored['bot_page_url'] ?? ''), sprintf(
            /* translators: %s: URL */
            __('Named in the fetch user agent. Empty means %s.', 'echo-robots-guard'),
            rtrim((string) home_url('/'), '/') . '/bot'
        ));
        $this->text('contact_email', __('Contact email for opt-outs', 'echo-robots-guard'), $s->contactEmail(), __('Shown in the statement. Optional.', 'echo-robots-guard'));
        $this->text('skip_hosts', __('Skip hosts', 'echo-robots-guard'), implode(' ', $s->skipHosts()), __('Space separated. A leading "." also matches subdomains. Use for aggregator redirect hosts.', 'echo-robots-guard'));

        echo '<tr><th scope="row">' . esc_html__('Fetch user agent', 'echo-robots-guard') . '</th><td><code>'
            . esc_html($s->userAgent()) . '</code></td></tr>';
        echo '</table>';
        submit_button($s->isConfirmed() ? __('Save settings', 'echo-robots-guard') : __('Confirm and save', 'echo-robots-guard'));
        echo '</form>';
    }

    /**
     * @param array<string, string> $choices
     */
    private function select(string $key, string $label, string $current, array $choices): void
    {
        $locked = $this->settings->isConstant($key);
        echo '<tr><th scope="row"><label for="erg-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
        echo '<select id="erg-' . esc_attr($key) . '" name="echo_robots_guard[' . esc_attr($key) . ']"' . ($locked ? ' disabled' : '') . '>';
        foreach ($choices as $value => $text) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($value),
                $value === $current ? ' selected' : '',
                esc_html($text)
            );
        }
        echo '</select>';
        $this->lockedNote($key);
        echo '</td></tr>';
    }

    private function text(string $key, string $label, string $value, string $help): void
    {
        $locked = $this->settings->isConstant($key);
        echo '<tr><th scope="row"><label for="erg-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
        printf(
            '<input type="text" class="regular-text" id="erg-%1$s" name="echo_robots_guard[%1$s]" value="%2$s"%3$s>',
            esc_attr($key),
            esc_attr($value),
            $locked ? ' readonly' : ''
        );
        echo '<p class="description">' . esc_html($help) . '</p>';
        $this->lockedNote($key);
        echo '</td></tr>';
    }

    private function lockedNote(string $key): void
    {
        if ($this->settings->isConstant($key)) {
            echo '<p class="description">' . esc_html(sprintf(
                /* translators: %s: constant name */
                __('Fixed in code by %s (read-only).', 'echo-robots-guard'),
                Settings::CONSTANTS[$key]
            )) . '</p>';
        }
    }

    private function renderCounters(): void
    {
        echo '<h2>' . esc_html__('Counters', 'echo-robots-guard') . '</h2>';
        $echo = $this->recorder->summary('echo');
        $api = $this->recorder->summary('api');
        $keys = Recorder::KEYS;
        foreach (Recorder::FETCH_ERROR_CLASSES as $class) {
            $keys[] = 'fetch_error_' . $class;
        }
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Counter', 'echo-robots-guard') . '</th><th>'
            . esc_html__('Echo today', 'echo-robots-guard') . '</th><th>' . esc_html__('Echo all time', 'echo-robots-guard')
            . '</th><th>' . esc_html__('API today', 'echo-robots-guard') . '</th><th>' . esc_html__('API all time', 'echo-robots-guard')
            . '</th></tr></thead><tbody>';
        foreach ($keys as $key) {
            printf(
                '<tr><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td></tr>',
                esc_html($key),
                (int) ($echo['today'][$key] ?? 0),
                (int) ($echo['total'][$key] ?? 0),
                (int) ($api['today'][$key] ?? 0),
                (int) ($api['total'][$key] ?? 0)
            );
        }
        echo '</tbody></table>';
        $last = $this->recorder->lastEchoCall();
        echo '<p>' . esc_html($last === null
            ? __('No Echo calls seen yet.', 'echo-robots-guard')
            : sprintf(
                /* translators: %s: date and time */
                __('Last Echo call: %s UTC.', 'echo-robots-guard'),
                gmdate('Y-m-d H:i', $last)
            )) . '</p>';
    }

    private function renderLog(): void
    {
        echo '<h2>' . esc_html__('Blocked and would-block (last 100)', 'echo-robots-guard') . '</h2>';
        $entries = $this->recorder->entries();
        if ($entries === []) {
            echo '<p>' . esc_html__('Nothing yet.', 'echo-robots-guard') . '</p>';

            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Time (UTC)', 'echo-robots-guard') . '</th><th>'
            . esc_html__('Mode', 'echo-robots-guard') . '</th><th>URL</th><th>' . esc_html__('Rule', 'echo-robots-guard')
            . '</th></tr></thead><tbody>';
        foreach ($entries as $entry) {
            printf(
                '<tr><td>%s</td><td>%s</td><td><code>%s</code></td><td><code>%s</code></td></tr>',
                esc_html(gmdate('Y-m-d H:i', (int) ($entry['time'] ?? 0))),
                esc_html((string) ($entry['mode'] ?? '')),
                esc_html((string) ($entry['url'] ?? '')),
                esc_html((string) ($entry['rule'] ?? ''))
            );
        }
        echo '</tbody></table>';
    }

    private function renderCache(): void
    {
        echo '<h2>' . esc_html__('robots.txt cache', 'echo-robots-guard') . '</h2>';
        $canClear = current_user_can(self::clearCapability());
        $long = $this->longUnreachable();
        if ($long !== []) {
            echo '<h3>' . esc_html__('Unreachable for more than 7 days', 'echo-robots-guard') . '</h3><ul>';
            foreach ($long as $entry) {
                printf(
                    '<li><code>%s</code> %s</li>',
                    esc_html($entry->origin),
                    esc_html(sprintf(
                        /* translators: %s: date */
                        __('since %s UTC', 'echo-robots-guard'),
                        gmdate('Y-m-d', (int) $entry->unreachableSince)
                    ))
                );
            }
            echo '</ul>';
        }
        $entries = $this->cache->all(200);
        if ($entries === []) {
            echo '<p>' . esc_html__('The cache is empty.', 'echo-robots-guard') . '</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Origin', 'echo-robots-guard') . '</th><th>'
                . esc_html__('Outcome', 'echo-robots-guard') . '</th><th>HTTP</th><th>' . esc_html__('Fetched (UTC)', 'echo-robots-guard')
                . '</th><th>' . esc_html__('Expires (UTC)', 'echo-robots-guard') . '</th><th></th></tr></thead><tbody>';
            foreach ($entries as $entry) {
                echo '<tr><td><code>' . esc_html($entry->origin) . '</code></td><td>' . esc_html($entry->outcome
                    . ($entry->errorClass !== null ? ' (' . $entry->errorClass . ')' : '')) . '</td><td>'
                    . esc_html($entry->status === null ? '' : (string) $entry->status) . '</td><td>'
                    . esc_html(gmdate('Y-m-d H:i', $entry->fetchedAt)) . '</td><td>'
                    . esc_html(gmdate('Y-m-d H:i', $entry->expiresAt)) . '</td><td>';
                if ($canClear) {
                    $this->clearForm($entry->origin, __('Clear', 'echo-robots-guard'));
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        if ($canClear) {
            $this->clearForm('', __('Clear the whole cache', 'echo-robots-guard'));
        }
    }

    private function clearForm(string $origin, string $label): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::CLEAR_ACTION) . '">';
        echo '<input type="hidden" name="origin" value="' . esc_attr($origin) . '">';
        wp_nonce_field(self::CLEAR_ACTION);
        submit_button($label, 'secondary small', 'submit', false);
        echo '</form>';
    }

    /**
     * @return array<mixed>
     */
    private function post(): array
    {
        // Nonce verified in the handlers through check_admin_referer().
        return wp_unslash($_POST); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    }

    private function redirect(string $url): void
    {
        wp_safe_redirect($url);
        exit;
    }
}
