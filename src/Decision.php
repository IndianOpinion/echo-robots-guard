<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard;

/**
 * The answer to "may this token fetch this URL?" (A1). Read-only.
 *
 * $allowed is true, false, or null when the URL was not checked.
 */
final class Decision
{
    /** A rule matched; $rule says which. */
    public const RULE = 'rule';

    /** robots.txt was read and no rule matched (or no group applies). */
    public const NO_RULE = 'no_rule';

    /** The URL is /robots.txt itself, which is always allowed (M9). */
    public const ROBOTS_TXT = 'robots_txt';

    /** robots.txt answered 4xx or too many redirects: allow all (H6, H8). */
    public const UNAVAILABLE = 'unavailable';

    /** robots.txt unreachable; the failure policy decided (H10, F1, F2). */
    public const UNREACHABLE = 'unreachable';

    /** robots.txt unreachable; a stale 2xx copy decided (H11). */
    public const STALE_COPY = 'stale_copy';

    /** The per-request fetch cap was reached; the failure policy decided. */
    public const FETCH_CAP = 'fetch_cap';

    /** Host on the skip list (E13). Not checked. */
    public const SKIP_LIST = 'skip_list';

    /** Not an absolute http(s) URL (H15). Not checked. */
    public const NOT_HTTP_URL = 'not_http_url';

    /** This plugin failed; the URL was let through (E9, F4). */
    public const INTERNAL_ERROR = 'internal_error';

    public const REASONS = [
        self::RULE, self::NO_RULE, self::ROBOTS_TXT, self::UNAVAILABLE, self::UNREACHABLE, self::STALE_COPY,
        self::FETCH_CAP, self::SKIP_LIST, self::NOT_HTTP_URL, self::INTERNAL_ERROR,
    ];

    /**
     * @param array{agent: ?string, directive: string, pattern: string}|null $rule
     * @param string $cache One of "hit", "miss", "stale", "none".
     */
    public function __construct(
        public readonly string $url,
        public readonly ?bool $allowed,
        public readonly string $reason,
        public readonly ?array $rule = null,
        public readonly ?string $origin = null,
        public readonly string $cache = 'none',
        public readonly ?string $token = null,
        public readonly ?string $failPolicy = null
    ) {
    }

    public static function notChecked(string $url, string $reason, ?string $origin = null, ?string $token = null): self
    {
        return new self($url, null, $reason, null, $origin, 'none', $token);
    }

    public function isChecked(): bool
    {
        return $this->allowed !== null;
    }

    public function isDisallowed(): bool
    {
        return $this->allowed === false;
    }

    /**
     * One line for logs and the admin page: the rule that matched, or why
     * there was none.
     */
    public function ruleText(): string
    {
        if ($this->rule !== null) {
            return sprintf(
                'User-agent: %s / %s: %s',
                $this->rule['agent'] ?? '*',
                ucfirst($this->rule['directive']),
                $this->rule['pattern']
            );
        }
        if ($this->reason === self::UNREACHABLE || $this->reason === self::FETCH_CAP) {
            $what = $this->reason === self::FETCH_CAP ? 'fetch cap reached' : 'unreachable';

            return $what . ', fail ' . ($this->failPolicy ?? 'closed');
        }

        return $this->reason;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'allowed' => $this->allowed,
            'result' => $this->allowed === null ? 'not checked' : ($this->allowed ? 'allowed' : 'disallowed'),
            'reason' => $this->reason,
            'rule' => $this->rule === null ? null : $this->ruleText(),
            'origin' => $this->origin,
            'cache' => $this->cache,
            'token' => $this->token,
        ];
    }
}
