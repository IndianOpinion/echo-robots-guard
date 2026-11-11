<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard;

use IndianOpinion\EchoRobotsGuard\Store\EntryStore;

/**
 * The robots.txt cache: the object cache in front of the plugin's table
 * (decision 1). Entries are network-wide (S2) and keyed by origin only, never
 * by token (C1, G8). Losing an object cache entry only costs a table read;
 * losing a table row only costs a refetch (C9).
 */
class Cache
{
    public const GROUP = 'echo_robots_guard';

    public function __construct(private EntryStore $store, private Clock $clock)
    {
    }

    public function get(string $origin): ?CacheEntry
    {
        $found = false;
        $row = wp_cache_get(CacheEntry::hash($origin), self::GROUP, false, $found);
        if ($found && is_array($row)) {
            $entry = CacheEntry::fromRow($row);
            if ($entry !== null) {
                return $entry;
            }
        }
        $entry = $this->store->get($origin);
        if ($entry !== null) {
            $this->remember($entry);
        }

        return $entry;
    }

    /**
     * Returns false when the table write failed. The caller's answer does not
     * change (C10).
     */
    public function put(CacheEntry $entry): bool
    {
        $stored = $this->store->put($entry);
        $this->remember($entry);

        return $stored;
    }

    public function delete(string $origin): bool
    {
        wp_cache_delete(CacheEntry::hash($origin), self::GROUP);

        return $this->store->delete($origin);
    }

    public function clear(): bool
    {
        if (function_exists('wp_cache_supports') && wp_cache_supports('flush_group')) {
            wp_cache_flush_group(self::GROUP);
        } else {
            foreach ($this->store->all(PHP_INT_MAX) as $entry) {
                wp_cache_delete(CacheEntry::hash($entry->origin), self::GROUP);
            }
        }

        return $this->store->clear();
    }

    /**
     * @return list<CacheEntry>
     */
    public function all(int $limit = 1000): array
    {
        return $this->store->all($limit);
    }

    public function purge(int $staleWindow): int
    {
        return $this->store->purge($this->clock->now(), $staleWindow);
    }

    private function remember(CacheEntry $entry): void
    {
        $ttl = max(60, $entry->expiresAt - $this->clock->now());
        wp_cache_set(CacheEntry::hash($entry->origin), $entry->toRow(), self::GROUP, $ttl);
    }
}
