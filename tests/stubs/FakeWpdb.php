<?php

declare(strict_types=1);

/**
 * A tiny in-memory stand-in for $wpdb that understands exactly the queries
 * the plugin's TableStore sends. It is not a SQL engine.
 */
class FakeWpdb
{
    public string $prefix = 'wp_';

    public string $base_prefix = 'wp_';

    public string $last_error = '';

    public bool $failWrites = false;

    /** @var array<string, array<string, array<string, mixed>>> table => key => row */
    public array $tables = [];

    /** @var list<string> */
    public array $queries = [];

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4';
    }

    public function prepare(string $query, mixed ...$args): string
    {
        $i = 0;

        return (string) preg_replace_callback('/%[sd]/', static function (array $m) use ($args, &$i): string {
            $value = $args[$i++] ?? '';
            if ($m[0] === '%d') {
                return (string) (int) $value;
            }

            return "'" . addslashes((string) $value) . "'";
        }, $query);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get_row(string $query, string $output = 'ARRAY_A'): ?array
    {
        $this->queries[] = $query;
        if (preg_match("/FROM (\S+) WHERE origin_hash = '([0-9a-f]+)'/", $query, $m) === 1) {
            return $this->tables[$m[1]][$m[2]] ?? null;
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function get_results(string $query, string $output = 'ARRAY_A'): array
    {
        $this->queries[] = $query;
        if (preg_match('/FROM (\S+)/', $query, $m) !== 1 || !isset($this->tables[$m[1]])) {
            return [];
        }
        $rows = array_values($this->tables[$m[1]]);
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['origin'], (string) $b['origin']));
        if (preg_match('/LIMIT (\d+)/', $query, $l) === 1) {
            $rows = array_slice($rows, 0, (int) $l[1]);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>|null    $format
     */
    public function replace(string $table, array $data, ?array $format = null): int|false
    {
        if ($this->failWrites || !isset($this->tables[$table])) {
            return false;
        }
        $this->tables[$table][(string) $data['origin_hash']] = $data;

        return 1;
    }

    /**
     * @param array<string, mixed> $where
     * @param list<string>|null    $format
     */
    public function delete(string $table, array $where, ?array $format = null): int|false
    {
        if (!isset($this->tables[$table])) {
            return false;
        }
        $key = (string) $where['origin_hash'];
        $existed = isset($this->tables[$table][$key]);
        unset($this->tables[$table][$key]);

        return $existed ? 1 : 0;
    }

    public function query(string $query): int|bool
    {
        $this->queries[] = $query;
        if (preg_match('/^DROP TABLE IF EXISTS (\S+)/', $query, $m) === 1) {
            unset($this->tables[$m[1]]);

            return true;
        }
        if (preg_match('/^DELETE FROM (\S+)$/', $query, $m) === 1) {
            $n = count($this->tables[$m[1]] ?? []);
            if (isset($this->tables[$m[1]])) {
                $this->tables[$m[1]] = [];
            }

            return $n;
        }
        if (preg_match('/^DELETE FROM (\S+) WHERE expires_at < (\d+) AND \(body_fetched_at IS NULL OR body_fetched_at < (-?\d+)\)/', $query, $m) === 1) {
            $n = 0;
            foreach ($this->tables[$m[1]] ?? [] as $key => $row) {
                if ((int) $row['expires_at'] < (int) $m[2] && ($row['body_fetched_at'] === null || (int) $row['body_fetched_at'] < (int) $m[3])) {
                    unset($this->tables[$m[1]][$key]);
                    $n++;
                }
            }

            return $n;
        }

        return false;
    }

    public function createFromSql(string $sql): void
    {
        if (preg_match('/CREATE TABLE (\S+)/', $sql, $m) === 1) {
            $this->tables[$m[1]] ??= [];
        }
    }
}
