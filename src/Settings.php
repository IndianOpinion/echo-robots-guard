<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard;

use IndianOpinion\EchoRobotsGuard\Robots\Parser;

/**
 * Per-site settings (S1). Each value is resolved in this order: a constant
 * if defined (S5), else the saved option, else the default; then the
 * setting's filter; then validation. A value that fails validation falls back
 * to the default and is reported by rejected().
 */
class Settings
{
    public const OPTION = 'echo_robots_guard_settings';

    public const MODES = ['enforce', 'shadow', 'off'];

    public const POLICIES = ['closed', 'open'];

    public const DEFAULT_TOKEN = 'WordPressImporterBot';

    public const MIN_TTL = 300;

    public const MAX_TTL = 604800;

    public const RFC_TTL = 86400;

    public const MIN_ERROR_TTL = 60;

    public const MAX_STALE_WINDOW = 7776000;

    public const DEFAULT_MAX_FETCHES = 20;

    /**
     * Constant names that fix a setting in code.
     */
    public const CONSTANTS = [
        'mode' => 'ECHO_ROBOTS_GUARD_MODE',
        'token' => 'ECHO_ROBOTS_GUARD_TOKEN',
        'fail_policy' => 'ECHO_ROBOTS_GUARD_FAIL_POLICY',
        'ttl' => 'ECHO_ROBOTS_GUARD_TTL',
        'error_ttl' => 'ECHO_ROBOTS_GUARD_ERROR_TTL',
        'stale_window' => 'ECHO_ROBOTS_GUARD_STALE_WINDOW',
        'timeout' => 'ECHO_ROBOTS_GUARD_TIMEOUT',
        'bot_page_url' => 'ECHO_ROBOTS_GUARD_BOT_PAGE_URL',
        'skip_hosts' => 'ECHO_ROBOTS_GUARD_SKIP_HOSTS',
        'contact_email' => 'ECHO_ROBOTS_GUARD_CONTACT_EMAIL',
        'max_fetches' => 'ECHO_ROBOTS_GUARD_MAX_FETCHES',
    ];

    /**
     * Filter names, one per setting.
     */
    public const FILTERS = [
        'mode' => 'echo_robots_guard_mode',
        'token' => 'echo_robots_guard_token',
        'fail_policy' => 'echo_robots_guard_fail_policy',
        'ttl' => 'echo_robots_guard_ttl',
        'error_ttl' => 'echo_robots_guard_error_ttl',
        'stale_window' => 'echo_robots_guard_stale_window',
        'timeout' => 'echo_robots_guard_timeout',
        'bot_page_url' => 'echo_robots_guard_bot_page_url',
        'skip_hosts' => 'echo_robots_guard_skip_hosts',
        'contact_email' => 'echo_robots_guard_contact_email',
        'max_fetches' => 'echo_robots_guard_max_fetches_per_request',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'mode' => 'enforce',
            'token' => self::tokenFromTitle((string) get_option('blogname', '')),
            'fail_policy' => 'closed',
            'ttl' => self::RFC_TTL,
            'error_ttl' => 3600,
            'stale_window' => 2592000,
            'timeout' => 8,
            'bot_page_url' => '',
            'skip_hosts' => [],
            'contact_email' => '',
            'max_fetches' => self::DEFAULT_MAX_FETCHES,
            'confirmed' => false,
        ];
    }

    /**
     * Default product token (decision 2): the site title as CamelCase words
     * of letters, "_" and "-", ending in "Bot". "Daily Example" becomes
     * "DailyExampleBot". Falls back to "WordPressImporterBot".
     */
    public static function tokenFromTitle(string $title): string
    {
        if (function_exists('remove_accents')) {
            $title = remove_accents($title);
        }
        $words = preg_split('/[^A-Za-z]+/', $title, -1, PREG_SPLIT_NO_EMPTY);
        $token = '';
        foreach ($words === false ? [] : $words as $word) {
            $token .= ucfirst($word);
        }
        if ($token === '') {
            return self::DEFAULT_TOKEN;
        }
        if (strcasecmp(substr($token, -3), 'bot') === 0) {
            $token = substr($token, 0, -3);
        }

        return substr($token, 0, 61) . 'Bot';
    }

    public static function isValidToken(string $token): bool
    {
        return preg_match('/^[A-Za-z_-]{1,64}$/', $token) === 1;
    }

    /**
     * The saved option, created with defaults on first read so the derived
     * token stays stable even if the site title changes later (S4, S5).
     *
     * @return array<string, mixed>
     */
    public function stored(): array
    {
        $saved = get_option(self::OPTION, null);
        if (!is_array($saved)) {
            $saved = self::defaults();
            add_option(self::OPTION, $saved, '', true);
        }

        return $saved + self::defaults();
    }

    public function isConstant(string $key): bool
    {
        return isset(self::CONSTANTS[$key]) && defined(self::CONSTANTS[$key]);
    }

    public function isConfirmed(): bool
    {
        return (bool) ($this->stored()['confirmed'] ?? false);
    }

    /**
     * Resolves one setting. The second element is false when the value was
     * rejected and the default used instead.
     *
     * @return array{0: mixed, 1: bool}
     */
    private function resolve(string $key): array
    {
        $stored = $this->stored();
        $value = $this->isConstant($key) ? constant(self::CONSTANTS[$key]) : ($stored[$key] ?? null);
        if (isset(self::FILTERS[$key])) {
            $value = apply_filters(self::FILTERS[$key], $value);
        }
        $clean = $this->validate($key, $value);
        if ($clean === null) {
            return [self::defaults()[$key], false];
        }

        return [$clean, true];
    }

    /**
     * Returns the cleaned value, or null when it is not acceptable.
     */
    public function validate(string $key, mixed $value): mixed
    {
        switch ($key) {
            case 'mode':
                return is_string($value) && in_array($value, self::MODES, true) ? $value : null;
            case 'fail_policy':
                return is_string($value) && in_array($value, self::POLICIES, true) ? $value : null;
            case 'token':
                return is_string($value) && self::isValidToken($value) ? $value : null;
            case 'ttl':
                return self::intIn($value, self::MIN_TTL, self::MAX_TTL);
            case 'error_ttl':
                return self::intIn($value, self::MIN_ERROR_TTL, self::MAX_TTL);
            case 'stale_window':
                return self::intIn($value, 0, self::MAX_STALE_WINDOW);
            case 'timeout':
                return self::intIn($value, 1, 30);
            case 'max_fetches':
                return self::intIn($value, 1, 10000);
            case 'bot_page_url':
                if ($value === '' || $value === null) {
                    return '';
                }

                return is_string($value) && self::isHttpUrl($value) ? $value : null;
            case 'contact_email':
                if ($value === '' || $value === null) {
                    return '';
                }

                return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null;
            case 'skip_hosts':
                return self::hostList($value);
        }

        return null;
    }

    /**
     * Names of settings whose saved, constant or filtered value was rejected.
     *
     * @return list<string>
     */
    public function rejected(): array
    {
        $rejected = [];
        foreach (array_keys(self::FILTERS) as $key) {
            if (!$this->resolve($key)[1]) {
                $rejected[] = $key;
            }
        }

        return $rejected;
    }

    public function mode(): string
    {
        return (string) $this->resolve('mode')[0];
    }

    public function token(): string
    {
        return (string) $this->resolve('token')[0];
    }

    public function failPolicy(): string
    {
        return (string) $this->resolve('fail_policy')[0];
    }

    public function failsOpen(): bool
    {
        return $this->failPolicy() === 'open';
    }

    public function ttl(): int
    {
        return (int) $this->resolve('ttl')[0];
    }

    /**
     * Never longer than the success TTL (C4).
     */
    public function errorTtl(): int
    {
        return min((int) $this->resolve('error_ttl')[0], $this->ttl());
    }

    public function staleWindow(): int
    {
        return (int) $this->resolve('stale_window')[0];
    }

    public function timeout(): int
    {
        return (int) $this->resolve('timeout')[0];
    }

    public function maxFetches(): int
    {
        return (int) $this->resolve('max_fetches')[0];
    }

    public function botPageUrl(): string
    {
        $url = (string) $this->resolve('bot_page_url')[0];
        if ($url === '') {
            $url = rtrim((string) home_url('/'), '/') . '/bot';
        }

        return $url;
    }

    public function contactEmail(): string
    {
        return (string) $this->resolve('contact_email')[0];
    }

    /**
     * @return list<string>
     */
    public function skipHosts(): array
    {
        /** @var list<string> $hosts */
        $hosts = $this->resolve('skip_hosts')[0];

        return $hosts;
    }

    /**
     * True when a host is on the skip list. An entry starting with "." also
     * matches every subdomain (".example.com" matches "news.example.com").
     */
    public function isSkipped(string $host): bool
    {
        foreach ($this->skipHosts() as $entry) {
            if ($entry === $host) {
                return true;
            }
            if (str_starts_with($entry, '.') && (str_ends_with($host, $entry) || $host === substr($entry, 1))) {
                return true;
            }
        }

        return false;
    }

    public function maxBytes(): int
    {
        $value = apply_filters('echo_robots_guard_max_bytes', Parser::MIN_BYTES);

        return max(Parser::MIN_BYTES, is_numeric($value) ? (int) $value : Parser::MIN_BYTES);
    }

    public function maxRules(): int
    {
        $value = apply_filters('echo_robots_guard_max_rules', Parser::DEFAULT_MAX_RULES);

        return is_numeric($value) && (int) $value >= 0 ? (int) $value : Parser::DEFAULT_MAX_RULES;
    }

    /**
     * @return list<string>
     */
    public function allowedInternalHosts(): array
    {
        return self::hostList(apply_filters('echo_robots_guard_allowed_internal_hosts', [])) ?? [];
    }

    public function userAgent(?string $token = null): string
    {
        $token ??= $this->token();
        $default = sprintf('%s/%s (+%s)', $token, Plugin::VERSION, $this->botPageUrl());
        $value = apply_filters('echo_robots_guard_user_agent', $default, $token);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * Validates and saves settings from the admin form. Settings fixed by a
     * constant are left alone. Returns the keys that were rejected; those
     * keep their previous value.
     *
     * @param array<string, mixed> $input
     * @return list<string>
     */
    public function save(array $input): array
    {
        $stored = $this->stored();
        $rejected = [];
        foreach (array_keys(self::FILTERS) as $key) {
            if (!array_key_exists($key, $input) || $this->isConstant($key)) {
                continue;
            }
            $value = $input[$key];
            if (in_array($key, ['ttl', 'error_ttl', 'stale_window', 'timeout', 'max_fetches'], true) && is_string($value)) {
                $value = trim($value);
            }
            if ($key === 'skip_hosts' && is_string($value)) {
                $value = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
            }
            $clean = $this->validate($key, $value);
            if ($clean === null) {
                $rejected[] = $key;
                continue;
            }
            $stored[$key] = $clean;
        }
        $stored['confirmed'] = true;
        update_option(self::OPTION, $stored, true);

        return $rejected;
    }

    /**
     * Admin warnings that are not errors (C3, F2).
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];
        if ($this->ttl() > self::RFC_TTL) {
            $warnings[] = 'ttl_over_24h';
        }
        if ($this->failsOpen()) {
            $warnings[] = 'fail_open';
        }

        return $warnings;
    }

    private static function intIn(mixed $value, int $min, int $max): ?int
    {
        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $int = (int) $value;
        } else {
            return null;
        }

        return $int >= $min && $int <= $max ? $int : null;
    }

    private static function isHttpUrl(string $value): bool
    {
        $parts = parse_url($value);

        return is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower($parts['scheme']), ['http', 'https'], true);
    }

    /**
     * @return list<string>|null
     */
    private static function hostList(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($value)) {
            return null;
        }
        $hosts = [];
        foreach ($value as $host) {
            if (!is_string($host)) {
                return null;
            }
            $host = strtolower(trim($host));
            if ($host === '') {
                continue;
            }
            if (preg_match('/^\.?[a-z0-9]([a-z0-9.-]*[a-z0-9])?$|^\[[0-9a-f:.]+\]$/', $host) !== 1) {
                return null;
            }
            $hosts[] = $host;
        }

        return array_values(array_unique($hosts));
    }
}
