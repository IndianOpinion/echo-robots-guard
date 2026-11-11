<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Store;

use IndianOpinion\EchoRobotsGuard\CacheEntry;

/**
 * Cache entries in one network-wide table (decision 1, S2). The table is
 * created on activation and, for must-use installs, on first use.
 *
 * Every query goes through $wpdb->prepare() or $wpdb's own insert, replace
 * and delete helpers; table names come from $wpdb->base_prefix only.
 */
final class TableStore implements EntryStore
{
    public const TABLE = 'echo_robots_guard_cache';

    public const DB_VERSION = '1';

    public const VERSION_OPTION = 'echo_robots_guard_db_version';

    private bool $ready = false;

    public static function tableName(): string
    {
        global $wpdb;

        return $wpdb->base_prefix . self::TABLE;
    }

    /**
     * Creates or upgrades the table when the stored schema version differs.
     */
    public static function install(): void
    {
        global $wpdb;
        if (get_site_option(self::VERSION_OPTION) === self::DB_VERSION) {
            return;
        }
        $table = self::tableName();
        $collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
  origin_hash char(40) NOT NULL,
  origin varchar(300) NOT NULL,
  outcome varchar(16) NOT NULL,
  status smallint(5) unsigned NULL,
  error_class varchar(32) NULL,
  body longtext NULL,
  bytes int(10) unsigned NOT NULL DEFAULT 0,
  fetched_at bigint(20) unsigned NOT NULL,
  expires_at bigint(20) unsigned NOT NULL,
  body_fetched_at bigint(20) unsigned NULL,
  unreachable_since bigint(20) unsigned NULL,
  PRIMARY KEY  (origin_hash),
  KEY expires_at (expires_at)
) {$collate};";
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta($sql);
        update_site_option(self::VERSION_OPTION, self::DB_VERSION);
    }

    public static function drop(): void
    {
        global $wpdb;
        $table = self::tableName();
        $wpdb->query("DROP TABLE IF EXISTS {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        delete_site_option(self::VERSION_OPTION);
    }

    public function get(string $origin): ?CacheEntry
    {
        global $wpdb;
        $this->ready();
        $table = self::tableName();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE origin_hash = %s", CacheEntry::hash($origin)),
            ARRAY_A
        );

        return is_array($row) ? CacheEntry::fromRow($row) : null;
    }

    public function put(CacheEntry $entry): bool
    {
        global $wpdb;
        $this->ready();
        $row = $entry->toRow();
        $formats = ['%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d'];

        return $wpdb->replace(self::tableName(), $row, $formats) !== false;
    }

    public function delete(string $origin): bool
    {
        global $wpdb;
        $this->ready();

        return $wpdb->delete(self::tableName(), ['origin_hash' => CacheEntry::hash($origin)], ['%s']) !== false;
    }

    public function clear(): bool
    {
        global $wpdb;
        $this->ready();
        $table = self::tableName();

        return $wpdb->query("DELETE FROM {$table}") !== false; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public function all(int $limit = 1000): array
    {
        global $wpdb;
        $this->ready();
        $table = self::tableName();
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} ORDER BY origin ASC LIMIT %d", max(1, $limit)),
            ARRAY_A
        );
        $entries = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $entry = is_array($row) ? CacheEntry::fromRow($row) : null;
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    public function purge(int $now, int $staleWindow): int
    {
        global $wpdb;
        $this->ready();
        $table = self::tableName();
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE expires_at < %d AND (body_fetched_at IS NULL OR body_fetched_at < %d)",
                $now,
                $now - $staleWindow
            )
        );

        return is_int($deleted) ? $deleted : 0;
    }

    private function ready(): void
    {
        if (!$this->ready) {
            self::install();
            $this->ready = true;
        }
    }
}
