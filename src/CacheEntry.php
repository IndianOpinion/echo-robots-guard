<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard;

use IndianOpinion\EchoRobotsGuard\Robots\RobotsFile;

/**
 * What is known about one origin's robots.txt (C2). Holds the parsed groups
 * of the last 2xx copy, never the raw body.
 *
 * When the latest outcome is "ok", $rules is the current file. When it is
 * "unreachable", $rules is the last 2xx copy (if any), usable as a stale
 * copy within the stale window (H11, C7). A fresh 4xx clears it.
 */
final class CacheEntry
{
    public const OK = 'ok';

    public const UNAVAILABLE = 'unavailable';

    public const UNREACHABLE = 'unreachable';

    public function __construct(
        public readonly string $origin,
        public readonly string $outcome,
        public readonly ?int $status,
        public readonly ?string $errorClass,
        public readonly ?RobotsFile $rules,
        public readonly int $bytes,
        public readonly int $fetchedAt,
        public readonly int $expiresAt,
        public readonly ?int $rulesFetchedAt,
        public readonly ?int $unreachableSince
    ) {
    }

    public function isFresh(int $now): bool
    {
        return $this->expiresAt > $now;
    }

    public function hasStaleCopy(int $now, int $staleWindow): bool
    {
        return $this->rules !== null
            && $this->rulesFetchedAt !== null
            && $staleWindow > 0
            && $this->rulesFetchedAt + $staleWindow > $now;
    }

    /**
     * Row for the cache table and the object cache.
     *
     * @return array<string, int|string|null>
     */
    public function toRow(): array
    {
        return [
            'origin_hash' => self::hash($this->origin),
            'origin' => $this->origin,
            'outcome' => $this->outcome,
            'status' => $this->status,
            'error_class' => $this->errorClass,
            'body' => $this->rules === null ? null : (string) json_encode($this->rules->toArray()),
            'bytes' => $this->bytes,
            'fetched_at' => $this->fetchedAt,
            'expires_at' => $this->expiresAt,
            'body_fetched_at' => $this->rulesFetchedAt,
            'unreachable_since' => $this->unreachableSince,
        ];
    }

    /**
     * @param array<mixed> $row
     */
    public static function fromRow(array $row): ?self
    {
        if (!isset($row['origin'], $row['outcome'], $row['fetched_at'], $row['expires_at'])) {
            return null;
        }
        $outcome = (string) $row['outcome'];
        if (!in_array($outcome, [self::OK, self::UNAVAILABLE, self::UNREACHABLE], true)) {
            return null;
        }
        $rules = null;
        if (isset($row['body']) && is_string($row['body']) && $row['body'] !== '') {
            $decoded = json_decode($row['body'], true);
            if (is_array($decoded)) {
                $rules = RobotsFile::fromArray($decoded);
            }
        }

        return new self(
            (string) $row['origin'],
            $outcome,
            isset($row['status']) ? (int) $row['status'] : null,
            isset($row['error_class']) && $row['error_class'] !== '' ? (string) $row['error_class'] : null,
            $rules,
            (int) ($row['bytes'] ?? 0),
            (int) $row['fetched_at'],
            (int) $row['expires_at'],
            isset($row['body_fetched_at']) ? (int) $row['body_fetched_at'] : null,
            isset($row['unreachable_since']) ? (int) $row['unreachable_since'] : null
        );
    }

    public static function hash(string $origin): string
    {
        return sha1($origin);
    }
}
