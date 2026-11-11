<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Cli;

use IndianOpinion\EchoRobotsGuard\Cache;
use IndianOpinion\EchoRobotsGuard\Guard;
use IndianOpinion\EchoRobotsGuard\Recorder;
use IndianOpinion\EchoRobotsGuard\Robots\Target;
use IndianOpinion\EchoRobotsGuard\Settings;
use WP_CLI;

/**
 * Checks URLs against robots.txt and manages the cache, counters and log.
 */
final class Command
{
    /** @var resource|null */
    private $stdin;

    /**
     * @param resource|null $stdin Stream for "check -"; defaults to STDIN.
     */
    public function __construct(
        private Settings $settings,
        private Guard $guard,
        private Cache $cache,
        private Recorder $recorder,
        $stdin = null
    ) {
        $this->stdin = $stdin;
    }

    /**
     * Checks one or more URLs against robots.txt.
     *
     * ## OPTIONS
     *
     * <url>...
     * : URLs to check. Use "-" to read URLs from standard input, one per line.
     *
     * [--token=<token>]
     * : Product token to check for. Defaults to the configured token.
     *
     * [--fresh]
     * : Ignore the cache and refetch robots.txt.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     * ---
     *
     * ## EXIT STATUS
     *
     * 0 when every URL is allowed or not checked, 1 when any is disallowed.
     *
     * ## EXAMPLES
     *
     *     wp echo-robots-guard check https://news.example/feed/
     *     wp echo-robots-guard check - < feeds.txt
     *
     * @param list<string>          $args
     * @param array<string, string|bool> $assoc
     */
    public function check(array $args, array $assoc): void
    {
        $urls = [];
        foreach ($args as $arg) {
            if ($arg === '-') {
                foreach ($this->readStdin() as $line) {
                    $urls[] = $line;
                }
                continue;
            }
            $urls[] = $arg;
        }
        if ($urls === []) {
            WP_CLI::error('No URLs given.');
        }
        $token = isset($assoc['token']) && is_string($assoc['token']) ? $assoc['token'] : null;
        if ($token !== null && !Settings::isValidToken($token)) {
            WP_CLI::error(sprintf('Invalid token "%s": use 1 to 64 letters, "_" or "-".', $token));
        }
        $fresh = !empty($assoc['fresh']);
        $format = isset($assoc['format']) && is_string($assoc['format']) ? $assoc['format'] : 'table';

        $rows = [];
        $disallowed = false;
        foreach ($urls as $url) {
            $decision = $this->guard->check($url, $token, 'api', $fresh);
            $disallowed = $disallowed || $decision->allowed === false;
            $row = $decision->toArray();
            $rows[] = [
                'url' => $row['url'],
                'result' => $row['result'],
                'reason' => $row['reason'],
                'rule' => $row['rule'] ?? '',
                'cache' => $row['cache'],
                'token' => $row['token'] ?? '',
            ];
        }
        $this->recorder->flush();
        WP_CLI\Utils\format_items($format, $rows, ['url', 'result', 'reason', 'rule', 'cache', 'token']);
        if ($disallowed) {
            WP_CLI::halt(1);
        }
    }

    /**
     * Lists or clears the robots.txt cache.
     *
     * ## OPTIONS
     *
     * <action>
     * : "list" or "clear".
     *
     * [<origin>]
     * : For "clear": one origin such as https://news.example. Omit to clear all.
     *
     * [--format=<format>]
     * : Output format for "list": table, json or csv.
     * ---
     * default: table
     * ---
     *
     * @param list<string>          $args
     * @param array<string, string|bool> $assoc
     */
    public function cache(array $args, array $assoc): void
    {
        $action = $args[0] ?? '';
        if ($action === 'clear') {
            $origin = $args[1] ?? '';
            if ($origin === '') {
                $this->cache->clear();
                WP_CLI::success('Cache cleared.');

                return;
            }
            $target = Target::fromUrl($origin);
            if ($target === null) {
                WP_CLI::error(sprintf('Not an http(s) origin: %s', $origin));

                return;
            }
            $this->cache->delete($target->origin());
            WP_CLI::success(sprintf('Cleared %s.', $target->origin()));

            return;
        }
        if ($action === 'list') {
            $rows = [];
            foreach ($this->cache->all() as $entry) {
                $rows[] = [
                    'origin' => $entry->origin,
                    'outcome' => $entry->outcome,
                    'status' => $entry->status ?? '',
                    'error' => $entry->errorClass ?? '',
                    'bytes' => $entry->bytes,
                    'fetched' => gmdate('Y-m-d H:i:s', $entry->fetchedAt),
                    'expires' => gmdate('Y-m-d H:i:s', $entry->expiresAt),
                ];
            }
            $format = isset($assoc['format']) && is_string($assoc['format']) ? $assoc['format'] : 'table';
            WP_CLI\Utils\format_items(
                $format,
                $rows,
                ['origin', 'outcome', 'status', 'error', 'bytes', 'fetched', 'expires']
            );

            return;
        }
        WP_CLI::error('Use "cache list" or "cache clear [<origin>]".');
    }

    /**
     * Shows counters for today and all time, or resets them.
     *
     * ## OPTIONS
     *
     * [--reset]
     * : Clear counters and the blocked log for this site.
     *
     * [--format=<format>]
     * : table, json or csv.
     * ---
     * default: table
     * ---
     *
     * @param list<string>          $args
     * @param array<string, string|bool> $assoc
     */
    public function stats(array $args, array $assoc): void
    {
        if (!empty($assoc['reset'])) {
            $this->recorder->reset();
            WP_CLI::success('Counters and log reset.');

            return;
        }
        $rows = [];
        foreach (['echo', 'api'] as $context) {
            $summary = $this->recorder->summary($context);
            $keys = array_unique(array_merge(array_keys($summary['total']), array_keys($summary['today'])));
            sort($keys);
            foreach ($keys as $key) {
                $rows[] = [
                    'context' => $context,
                    'counter' => $key,
                    'today' => $summary['today'][$key] ?? 0,
                    'total' => $summary['total'][$key] ?? 0,
                ];
            }
        }
        $format = isset($assoc['format']) && is_string($assoc['format']) ? $assoc['format'] : 'table';
        WP_CLI\Utils\format_items($format, $rows, ['context', 'counter', 'today', 'total']);
    }

    /**
     * Shows the blocked and would-block log, newest first.
     *
     * ## OPTIONS
     *
     * [--limit=<n>]
     * : How many entries. Default 20, at most 100.
     *
     * [--format=<format>]
     * : table, json or csv.
     * ---
     * default: table
     * ---
     *
     * @param list<string>          $args
     * @param array<string, string|bool> $assoc
     */
    public function log(array $args, array $assoc): void
    {
        $limit = isset($assoc['limit']) && is_numeric($assoc['limit']) ? (int) $assoc['limit'] : 20;
        $rows = [];
        foreach ($this->recorder->entries($limit) as $entry) {
            $rows[] = [
                'time' => gmdate('Y-m-d H:i:s', (int) ($entry['time'] ?? 0)),
                'mode' => (string) ($entry['mode'] ?? ''),
                'url' => (string) ($entry['url'] ?? ''),
                'rule' => (string) ($entry['rule'] ?? ''),
            ];
        }
        $format = isset($assoc['format']) && is_string($assoc['format']) ? $assoc['format'] : 'table';
        WP_CLI\Utils\format_items($format, $rows, ['time', 'mode', 'url', 'rule']);
    }

    /**
     * Shows the settings in effect.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, json or csv.
     * ---
     * default: table
     * ---
     *
     * @param list<string>          $args
     * @param array<string, string|bool> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        $s = $this->settings;
        $last = $this->recorder->lastEchoCall();
        $items = [
            'mode' => $s->mode(),
            'token' => $s->token(),
            'token_confirmed' => $s->isConfirmed() ? 'yes' : 'no',
            'fail_policy' => $s->failPolicy(),
            'ttl' => $s->ttl(),
            'error_ttl' => $s->errorTtl(),
            'stale_window' => $s->staleWindow(),
            'timeout' => $s->timeout(),
            'max_fetches' => $s->maxFetches(),
            'user_agent' => $s->userAgent(),
            'skip_hosts' => implode(' ', $s->skipHosts()),
            'last_echo_call' => $last === null ? 'never' : gmdate('Y-m-d H:i:s', $last),
            'rejected' => implode(',', $s->rejected()),
        ];
        $rows = [];
        foreach ($items as $key => $value) {
            $rows[] = ['setting' => $key, 'value' => (string) $value];
        }
        $format = isset($assoc['format']) && is_string($assoc['format']) ? $assoc['format'] : 'table';
        WP_CLI\Utils\format_items($format, $rows, ['setting', 'value']);
    }

    /**
     * @return list<string>
     */
    private function readStdin(): array
    {
        $stream = $this->stdin ?? (defined('STDIN') ? STDIN : null);
        if ($stream === null) {
            return [];
        }
        $urls = [];
        while (($line = fgets($stream)) !== false) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                $urls[] = $line;
            }
        }

        return $urls;
    }
}
