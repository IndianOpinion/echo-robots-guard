<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard;

/**
 * Counters and the blocked log, per site (S3).
 *
 * Writes are buffered for the whole request and saved once, on shutdown
 * (decision 5), so an import run that checks thousands of items costs two
 * option writes per site, not thousands. Both options are not autoloaded.
 */
class Recorder
{
    public const COUNTERS = 'echo_robots_guard_counters';

    public const LOG = 'echo_robots_guard_log';

    public const LOG_SIZE = 100;

    public const DAYS_KEPT = 30;

    public const CONTEXTS = ['echo', 'api'];

    /**
     * Every counter key, in display order. Fetch error classes are appended
     * as "fetch_error_<class>".
     */
    public const KEYS = [
        'checked', 'allowed', 'disallowed', 'blocked', 'would_block', 'not_checked', 'passed_through',
        'cache_hits', 'cache_misses', 'stale_used', 'fetches', 'fetch_errors', 'fetch_cap_reached',
        'cache_write_errors', 'rules_dropped', 'misspellings_accepted', 'internal_errors',
    ];

    public const FETCH_ERROR_CLASSES = [
        'timeout', 'dns', 'tls', 'refused', 'network', '5xx', '429', 'status', 'redirect', 'redirect_limit',
        'redirect_scheme', 'unsafe',
    ];

    /**
     * @var array<int, array{days?: array<string, array<string, array<string, int>>>, log?: list<array<string, mixed>>, echo_seen?: int}>
     */
    private array $buffer = [];

    public function __construct(private Clock $clock)
    {
    }

    public function count(string $context, string $key, int $by = 1): void
    {
        if ($by <= 0) {
            return;
        }
        $blog = $this->blog();
        $day = gmdate('Y-m-d', $this->clock->now());
        $current = $this->buffer[$blog]['days'][$day][$context][$key] ?? 0;
        $this->buffer[$blog]['days'][$day][$context][$key] = $current + $by;
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function log(array $entry): void
    {
        $blog = $this->blog();
        $this->buffer[$blog]['log'][] = ['time' => $this->clock->now()] + $entry;
    }

    public function seenEcho(): void
    {
        $this->buffer[$this->blog()]['echo_seen'] = $this->clock->now();
    }

    /**
     * Saves every buffered change. Runs on shutdown; safe to call more than
     * once. Never throws: a failed write is lost, which only costs counts.
     */
    public function flush(): void
    {
        $buffer = $this->buffer;
        $this->buffer = [];
        $current = $this->blog();
        foreach ($buffer as $blog => $changes) {
            $switched = false;
            if ($blog !== $current && function_exists('switch_to_blog')) {
                switch_to_blog($blog);
                $switched = true;
            }
            try {
                $this->write($changes);
            } catch (\Throwable $e) {
                // Counters are best effort; the import must not notice.
                unset($e);
            } finally {
                if ($switched) {
                    restore_current_blog();
                }
            }
        }
    }

    /**
     * Saved counters merged with what this request has not written yet.
     *
     * @return array{days: array<string, array<string, array<string, int>>>, total: array<string, array<string, int>>, last_echo_call: ?int, since: ?int}
     */
    public function counters(): array
    {
        $stored = $this->storedCounters();
        $pending = $this->buffer[$this->blog()] ?? null;
        if ($pending !== null) {
            $stored = $this->merge($stored, $pending['days'] ?? [], $pending['echo_seen'] ?? null);
        }

        return $stored;
    }

    /**
     * Today's and all-time numbers for one context.
     *
     * @return array{today: array<string, int>, total: array<string, int>}
     */
    public function summary(string $context): array
    {
        $counters = $this->counters();
        $today = gmdate('Y-m-d', $this->clock->now());

        return [
            'today' => $counters['days'][$today][$context] ?? [],
            'total' => $counters['total'][$context] ?? [],
        ];
    }

    /**
     * Newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(int $limit = self::LOG_SIZE): array
    {
        $stored = get_option(self::LOG, []);
        $stored = is_array($stored) ? array_values($stored) : [];
        $pending = $this->buffer[$this->blog()]['log'] ?? [];
        $all = array_merge(array_reverse($pending), $stored);

        return array_slice($all, 0, max(0, min($limit, self::LOG_SIZE)));
    }

    public function lastEchoCall(): ?int
    {
        return $this->counters()['last_echo_call'];
    }

    /**
     * Clears counters and the log for the current site.
     */
    public function reset(): void
    {
        unset($this->buffer[$this->blog()]);
        delete_option(self::COUNTERS);
        delete_option(self::LOG);
    }

    /**
     * @param array{days?: array<string, array<string, array<string, int>>>, log?: list<array<string, mixed>>, echo_seen?: ?int} $changes
     */
    private function write(array $changes): void
    {
        if (($changes['days'] ?? []) !== [] || ($changes['echo_seen'] ?? null) !== null) {
            $merged = $this->merge($this->storedCounters(), $changes['days'] ?? [], $changes['echo_seen'] ?? null);
            update_option(self::COUNTERS, $merged, false);
        }
        if (($changes['log'] ?? []) !== []) {
            $stored = get_option(self::LOG, []);
            $stored = is_array($stored) ? array_values($stored) : [];
            $log = array_slice(array_merge(array_reverse($changes['log']), $stored), 0, self::LOG_SIZE);
            update_option(self::LOG, $log, false);
        }
    }

    /**
     * @param array{days: array<string, array<string, array<string, int>>>, total: array<string, array<string, int>>, last_echo_call: ?int, since: ?int} $stored
     * @param array<string, array<string, array<string, int>>> $days
     * @return array{days: array<string, array<string, array<string, int>>>, total: array<string, array<string, int>>, last_echo_call: ?int, since: ?int}
     */
    private function merge(array $stored, array $days, ?int $echoSeen): array
    {
        foreach ($days as $day => $contexts) {
            foreach ($contexts as $context => $keys) {
                foreach ($keys as $key => $n) {
                    $stored['days'][$day][$context][$key] = ($stored['days'][$day][$context][$key] ?? 0) + $n;
                    $stored['total'][$context][$key] = ($stored['total'][$context][$key] ?? 0) + $n;
                }
            }
        }
        if ($echoSeen !== null) {
            $stored['last_echo_call'] = max($echoSeen, $stored['last_echo_call'] ?? 0);
        }
        $stored['since'] ??= $this->clock->now();
        ksort($stored['days']);
        $cutoff = gmdate('Y-m-d', $this->clock->now() - (self::DAYS_KEPT - 1) * 86400);
        foreach (array_keys($stored['days']) as $day) {
            if ($day < $cutoff) {
                unset($stored['days'][$day]);
            }
        }

        return $stored;
    }

    /**
     * @return array{days: array<string, array<string, array<string, int>>>, total: array<string, array<string, int>>, last_echo_call: ?int, since: ?int}
     */
    private function storedCounters(): array
    {
        $raw = get_option(self::COUNTERS, []);
        $raw = is_array($raw) ? $raw : [];

        /** @var array<string, array<string, array<string, int>>> $days */
        $days = is_array($raw['days'] ?? null) ? $raw['days'] : [];
        /** @var array<string, array<string, int>> $total */
        $total = is_array($raw['total'] ?? null) ? $raw['total'] : [];

        return [
            'days' => $days,
            'total' => $total,
            'last_echo_call' => isset($raw['last_echo_call']) && is_int($raw['last_echo_call']) ? $raw['last_echo_call'] : null,
            'since' => isset($raw['since']) && is_int($raw['since']) ? $raw['since'] : null,
        ];
    }

    private function blog(): int
    {
        return function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
    }
}
