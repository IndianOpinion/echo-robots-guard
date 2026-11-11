<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Robots;

/**
 * RFC 9309 robots.txt parser. Pure PHP, no WordPress.
 *
 * The parser never throws on bad input: every line it can read counts, and
 * every line it cannot read is skipped (RFC 9309 2.3.1.5). It never builds a
 * regular expression from the file and never evaluates anything in it.
 */
class Parser
{
    /** RFC 9309 2.5: the parsing limit MUST be at least 500 KiB. */
    public const MIN_BYTES = 512000;

    public const DEFAULT_MAX_RULES = 10000;

    /**
     * Misspellings accepted as a known key. RFC 9309 does not ask for this;
     * it is a leniency that matches what large crawlers do. Every accepted
     * misspelling is reported in RobotsFile::$misspellings.
     */
    public const MISSPELLINGS = [
        'dissallow' => 'disallow',
        'dissalow' => 'disallow',
        'disalow' => 'disallow',
        'diasllow' => 'disallow',
        'disallaw' => 'disallow',
        'disallwo' => 'disallow',
        'alow' => 'allow',
        'allwo' => 'allow',
        'alllow' => 'allow',
    ];

    private int $maxBytes;

    private int $maxRules;

    public function __construct(int $maxBytes = self::MIN_BYTES, int $maxRules = self::DEFAULT_MAX_RULES)
    {
        $this->maxBytes = max(self::MIN_BYTES, $maxBytes);
        $this->maxRules = max(0, $maxRules);
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    public function parse(string $body): RobotsFile
    {
        [$body, $truncated] = $this->limit($body);
        $bytesRead = strlen($body);

        if (str_starts_with($body, "\xEF\xBB\xBF")) {
            $body = substr($body, 3);
        }

        /** @var list<list<string>> $agents */
        $agents = [];
        /** @var list<list<Rule>> $rules */
        $rules = [];
        $current = -1;
        $sawRuleLine = false;
        $ruleCount = 0;
        $dropped = 0;
        $misspellings = [];

        // One pass in C finds every "key: value" line. Line endings are
        // unified first (P1). A key holds no "#" or ":"; the value stops at
        // "#" (P3); spaces and tabs around both are dropped (P4). Lines with
        // no colon before a "#" never match, so they are ignored (P6).
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        preg_match_all(
            '/^[ \t]*([^:#\n]*?)[ \t]*:[ \t]*([^#\n]*?)[ \t]*(?:#[^\n]*)?$/m',
            $body,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as [, $key, $value]) {
            $key = strtolower($key);

            if (isset(self::MISSPELLINGS[$key])) {
                $misspellings[$key] = true;
                $key = self::MISSPELLINGS[$key];
            }

            if ($key === 'user-agent') {
                if ($current < 0 || $sawRuleLine) {
                    $current++;
                    $agents[$current] = [];
                    $rules[$current] = [];
                    $sawRuleLine = false;
                }
                $token = self::productToken($value);
                if ($token !== null && !in_array($token, $agents[$current], true)) {
                    $agents[$current][] = $token;
                }
                continue;
            }

            if ($key !== 'allow' && $key !== 'disallow') {
                // Sitemap, Crawl-delay, Host and anything else: ignored, and
                // they neither start nor end a group (RFC 9309 2.2.4).
                continue;
            }

            if ($current < 0) {
                // Rules before the first User-agent line (P9).
                continue;
            }
            $sawRuleLine = true;

            if ($value === '') {
                // Empty Allow or Disallow is not a rule (P11, P12).
                continue;
            }

            if ($ruleCount >= $this->maxRules) {
                $dropped++;
                continue;
            }

            if ($value[0] !== '/' && $value[0] !== '*') {
                // Lenient: RFC 9309 2.2 requires a leading "/" (P13).
                $value = '/' . $value;
            }

            $rules[$current][] = new Rule($key === 'allow', Normalizer::pattern($value));
            $ruleCount++;
        }

        $result = [];
        foreach ($agents as $i => $groupAgents) {
            $result[] = new Group($groupAgents, $rules[$i]);
        }

        return new RobotsFile($result, $bytesRead, $truncated, $dropped, array_keys($misspellings));
    }

    /**
     * The product token of a User-agent value (RFC 9309 2.2.1): "*", or the
     * leading run of letters, "_" and "-". Null when the value names nobody.
     */
    public static function productToken(string $value): ?string
    {
        if ($value === '*') {
            return '*';
        }
        if (preg_match('/^[A-Za-z_-]+/', $value, $m) === 1) {
            return $m[0];
        }

        return null;
    }

    /**
     * Applies the byte limit. A line cut by the limit is discarded (P15).
     *
     * @return array{0: string, 1: bool}
     */
    private function limit(string $body): array
    {
        if (strlen($body) <= $this->maxBytes) {
            return [$body, false];
        }
        $next = $body[$this->maxBytes];
        $kept = substr($body, 0, $this->maxBytes);
        if ($next !== "\n" && $next !== "\r") {
            $lastLf = strrpos($kept, "\n");
            $lastCr = strrpos($kept, "\r");
            $cut = max($lastLf === false ? -1 : $lastLf, $lastCr === false ? -1 : $lastCr);
            $kept = $cut < 0 ? '' : substr($kept, 0, $cut + 1);
        }

        return [$kept, true];
    }
}
