<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Store;

use IndianOpinion\EchoRobotsGuard\CacheEntry;

/**
 * Persistent storage for cache entries.
 */
interface EntryStore
{
    public function get(string $origin): ?CacheEntry;

    public function put(CacheEntry $entry): bool;

    public function delete(string $origin): bool;

    public function clear(): bool;

    /**
     * @return list<CacheEntry>
     */
    public function all(int $limit = 1000): array;

    /**
     * Deletes entries that are expired and hold no stale copy younger than
     * the window. Returns the number deleted.
     */
    public function purge(int $now, int $staleWindow): int;
}
