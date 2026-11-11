<?php

/**
 * Minimal WordPress function stubs: enough for the plugin's own calls, no
 * more. Behaviour follows WordPress where the plugin depends on it.
 */

declare(strict_types=1);

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

/* Hooks ----------------------------------------------------------------- */

function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    WpStub::$hooks[$hook][$priority][] = [$callback, $args];
    ksort(WpStub::$hooks[$hook]);

    return true;
}

function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $callback, $priority, $args);
}

function has_filter(string $hook, mixed $callback = false): bool|int
{
    if (!isset(WpStub::$hooks[$hook])) {
        return false;
    }
    if ($callback === false) {
        return true;
    }
    foreach (WpStub::$hooks[$hook] as $priority => $callbacks) {
        foreach ($callbacks as $entry) {
            if ($entry[0] === $callback) {
                return $priority;
            }
        }
    }

    return false;
}

function remove_all_filters(string $hook): bool
{
    unset(WpStub::$hooks[$hook]);

    return true;
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    foreach (WpStub::$hooks[$hook] ?? [] as $callbacks) {
        foreach ($callbacks as [$callback, $accepted]) {
            $value = $callback(...array_slice([$value, ...$args], 0, max(1, $accepted)));
        }
    }

    return $value;
}

function do_action(string $hook, mixed ...$args): void
{
    foreach (WpStub::$hooks[$hook] ?? [] as $callbacks) {
        foreach ($callbacks as [$callback, $accepted]) {
            $callback(...array_slice($args, 0, $accepted));
        }
    }
}

/* Options --------------------------------------------------------------- */

function get_option(string $name, mixed $default = false): mixed
{
    if ($name === 'blogname') {
        return WpStub::$blogname;
    }

    return array_key_exists($name, WpStub::$options[WpStub::$blog] ?? [])
        ? WpStub::$options[WpStub::$blog][$name]
        : $default;
}

function add_option(string $name, mixed $value = '', string $deprecated = '', bool|string|null $autoload = null): bool
{
    if (array_key_exists($name, WpStub::$options[WpStub::$blog] ?? [])) {
        return false;
    }
    WpStub::$options[WpStub::$blog][$name] = $value;
    WpStub::$autoload[WpStub::$blog][$name] = $autoload !== false && $autoload !== 'no';

    return true;
}

function update_option(string $name, mixed $value, bool|string|null $autoload = null): bool
{
    WpStub::$options[WpStub::$blog][$name] = $value;
    if ($autoload !== null) {
        WpStub::$autoload[WpStub::$blog][$name] = $autoload !== false && $autoload !== 'no';
    }

    return true;
}

function delete_option(string $name): bool
{
    $existed = array_key_exists($name, WpStub::$options[WpStub::$blog] ?? []);
    unset(WpStub::$options[WpStub::$blog][$name], WpStub::$autoload[WpStub::$blog][$name]);

    return $existed;
}

function get_site_option(string $name, mixed $default = false): mixed
{
    return array_key_exists($name, WpStub::$siteOptions) ? WpStub::$siteOptions[$name] : $default;
}

function update_site_option(string $name, mixed $value): bool
{
    WpStub::$siteOptions[$name] = $value;

    return true;
}

function delete_site_option(string $name): bool
{
    unset(WpStub::$siteOptions[$name]);

    return true;
}

/* Multisite ------------------------------------------------------------- */

function is_multisite(): bool
{
    return WpStub::$multisite;
}

function get_current_blog_id(): int
{
    return WpStub::$blog;
}

function switch_to_blog(int $blog): bool
{
    WpStub::$blogStack[] = WpStub::$blog;
    WpStub::$blog = $blog;

    return true;
}

function restore_current_blog(): bool
{
    WpStub::$blog = array_pop(WpStub::$blogStack) ?? 1;

    return true;
}

/**
 * @param array<string, mixed> $args
 * @return list<int>
 */
function get_sites(array $args = []): array
{
    return WpStub::$sites;
}

/* Object cache ---------------------------------------------------------- */

function wp_cache_get(string $key, string $group = '', bool $force = false, ?bool &$found = null): mixed
{
    if (WpStub::$objectCache && array_key_exists($key, WpStub::$cache[$group] ?? [])) {
        $found = true;

        return WpStub::$cache[$group][$key];
    }
    $found = false;

    return false;
}

function wp_cache_set(string $key, mixed $data, string $group = '', int $expire = 0): bool
{
    if (WpStub::$objectCache) {
        WpStub::$cache[$group][$key] = $data;
    }

    return true;
}

function wp_cache_delete(string $key, string $group = ''): bool
{
    unset(WpStub::$cache[$group][$key]);

    return true;
}

function wp_cache_supports(string $feature): bool
{
    return $feature === 'flush_group';
}

function wp_cache_flush_group(string $group): bool
{
    unset(WpStub::$cache[$group]);

    return true;
}

/**
 * @param list<string>|string $groups
 */
function wp_cache_add_global_groups(array|string $groups): void
{
    foreach ((array) $groups as $group) {
        WpStub::$globalGroups[] = $group;
    }
}

/* HTTP ------------------------------------------------------------------ */

/**
 * @param array<string, mixed> $args
 * @return array<string, mixed>|WP_Error
 */
function wp_remote_get(string $url, array $args = []): array|WP_Error
{
    return erg_stub_http($url, $args, false);
}

/**
 * @param array<string, mixed> $args
 * @return array<string, mixed>|WP_Error
 */
function wp_safe_remote_get(string $url, array $args = []): array|WP_Error
{
    return erg_stub_http($url, $args, true);
}

/**
 * @param array<string, mixed> $args
 * @return array<string, mixed>|WP_Error
 */
function erg_stub_http(string $url, array $args, bool $safe): array|WP_Error
{
    $host = (string) parse_url($url, PHP_URL_HOST);
    if ($safe && in_array($host, WpStub::$unsafeHosts, true)) {
        return new WP_Error('http_request_failed', 'A valid URL was not provided.');
    }
    WpStub::$requests[] = ['url' => $url, 'args' => $args, 'safe' => $safe];
    $queue = WpStub::$http[$url] ?? null;
    if ($queue === null || $queue === []) {
        return ['response' => ['code' => 404], 'body' => '', 'headers' => []];
    }
    $response = count($queue) > 1 ? array_shift(WpStub::$http[$url]) : $queue[0];
    if ($response instanceof WP_Error) {
        return $response;
    }
    $body = (string) $response['body'];
    if (isset($args['limit_response_size'])) {
        $body = substr($body, 0, (int) $args['limit_response_size']);
    }

    return [
        'response' => ['code' => $response['status']],
        'body' => $body,
        'headers' => array_change_key_case($response['headers'] ?? []),
    ];
}

function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}

/**
 * @param array<string, mixed>|WP_Error $response
 */
function wp_remote_retrieve_response_code(array|WP_Error $response): int|string
{
    return is_array($response) ? (int) $response['response']['code'] : '';
}

/**
 * @param array<string, mixed>|WP_Error $response
 */
function wp_remote_retrieve_body(array|WP_Error $response): string
{
    return is_array($response) ? (string) $response['body'] : '';
}

/**
 * @param array<string, mixed>|WP_Error $response
 * @return array<string>|string
 */
function wp_remote_retrieve_header(array|WP_Error $response, string $header): array|string
{
    if (!is_array($response)) {
        return '';
    }

    return $response['headers'][strtolower($header)] ?? '';
}

/* Escaping and i18n ----------------------------------------------------- */

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function esc_html__(string $text, string $domain = 'default'): string
{
    return esc_html($text);
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_url(string $url): string
{
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

function load_plugin_textdomain(string $domain, bool $deprecated = false, string|false $path = false): bool
{
    return true;
}

function plugin_basename(string $file): string
{
    return basename(dirname($file)) . '/' . basename($file);
}

function home_url(string $path = ''): string
{
    return 'https://site.example' . $path;
}

function admin_url(string $path = ''): string
{
    return 'https://site.example/wp-admin/' . $path;
}

/**
 * @param array<string, string> $args
 */
function add_query_arg(array $args, string $url): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
}

function wp_unslash(mixed $value): mixed
{
    return $value;
}

/* Admin ----------------------------------------------------------------- */

function is_admin(): bool
{
    return WpStub::$admin;
}

function current_user_can(string $capability): bool
{
    return in_array($capability, WpStub::$caps, true);
}

function check_admin_referer(string $action): int
{
    $nonce = $_REQUEST['_wpnonce'] ?? '';
    if ($nonce !== 'nonce-' . $action) {
        throw new WpDieException('The link you followed has expired.');
    }

    return 1;
}

function wp_nonce_field(string $action): string
{
    $field = '<input type="hidden" name="_wpnonce" value="nonce-' . esc_attr($action) . '">';
    echo $field;

    return $field;
}

/**
 * @param array<string, mixed> $args
 */
function wp_die(string $message = '', string $title = '', array $args = []): never
{
    throw new WpDieException($message);
}

function submit_button(string $text = 'Save', string $type = 'primary', string $name = 'submit', bool $wrap = true): void
{
    echo '<button type="submit" name="' . esc_attr($name) . '">' . esc_html($text) . '</button>';
}

function add_management_page(string $pageTitle, string $menuTitle, string $capability, string $slug, callable $callback): string
{
    WpStub::$menuPages[] = [$slug, $capability];

    return 'tools_page_' . $slug;
}

function wp_safe_redirect(string $location): bool
{
    throw new WpRedirectException($location);
}

function get_current_screen(): ?object
{
    return null;
}

/* Shortcodes and cron --------------------------------------------------- */

function add_shortcode(string $tag, callable $callback): void
{
    WpStub::$shortcodes[$tag] = $callback;
}

function do_shortcode(string $content): string
{
    return (string) preg_replace_callback('/\[([a-z_]+)\]/', static function (array $m): string {
        $callback = WpStub::$shortcodes[$m[1]] ?? null;

        return $callback === null ? $m[0] : (string) $callback([], '', $m[1]);
    }, $content);
}

function wp_next_scheduled(string $hook): int|false
{
    return WpStub::$scheduled[$hook] ?? false;
}

function wp_schedule_event(int $timestamp, string $recurrence, string $hook): bool
{
    WpStub::$scheduled[$hook] = $timestamp;

    return true;
}

function wp_clear_scheduled_hook(string $hook): int
{
    unset(WpStub::$scheduled[$hook]);

    return 1;
}

function register_activation_hook(string $file, callable $callback): void
{
    add_action('activate_' . plugin_basename($file), $callback);
}

function register_deactivation_hook(string $file, callable $callback): void
{
    add_action('deactivate_' . plugin_basename($file), $callback);
}

/* Schema ---------------------------------------------------------------- */

/**
 * @return array<string, string>
 */
function dbDelta(string $sql): array
{
    $GLOBALS['wpdb']->createFromSql($sql);

    return [];
}

function __return_true_stub(): bool
{
    return true;
}
