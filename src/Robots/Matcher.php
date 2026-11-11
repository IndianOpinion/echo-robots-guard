<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Robots;

/**
 * Selects the group for a product token and decides a path (RFC 9309 2.2.1
 * to 2.2.3). Pure PHP, no WordPress.
 *
 * Matching splits each pattern on "*" and finds the pieces left to right with
 * strpos. There is no backtracking, so a hostile pattern such as
 * "/*a*a*a*a*b" costs one pass over the path per piece, never more.
 */
final class Matcher
{
    /**
     * The rules that apply to a token: the merged named groups if any exist
     * (G1, G3, G6), else the merged "*" groups (G4), else none (G5).
     *
     * @return array{agent: ?string, rules: list<Rule>}
     */
    public static function rulesFor(RobotsFile $file, string $token): array
    {
        $named = [];
        $namedAgent = null;
        $wildcard = [];
        $hasWildcard = false;

        foreach ($file->groups as $group) {
            $agent = $group->names($token);
            if ($agent !== null) {
                $namedAgent ??= $agent;
                foreach ($group->rules as $rule) {
                    // Identical rules decide identically; keep one (merging
                    // groups often repeats them).
                    $named[($rule->allow ? 'a' : 'd') . $rule->pattern] = $rule;
                }
            }
            if ($group->isWildcard()) {
                $hasWildcard = true;
                foreach ($group->rules as $rule) {
                    $wildcard[($rule->allow ? 'a' : 'd') . $rule->pattern] = $rule;
                }
            }
        }

        if ($namedAgent !== null) {
            return ['agent' => $namedAgent, 'rules' => array_values($named)];
        }
        if ($hasWildcard) {
            return ['agent' => '*', 'rules' => array_values($wildcard)];
        }

        return ['agent' => null, 'rules' => []];
    }

    /**
     * Decides a path that is already normalised with Normalizer::path().
     * The path includes "?" and the query when there is one.
     *
     * Rules are tried longest first (Allow first on a tie), so the first
     * match is the answer (M3, M4). A path that matches nothing costs one
     * pass over the rules.
     */
    public static function decide(RobotsFile $file, string $token, string $path): MatchResult
    {
        $selected = $file->selection($token);

        $pathOnly = strstr($path, '?', true);
        if (($pathOnly === false ? $path : $pathOnly) === '/robots.txt') {
            return new MatchResult(true, null, $selected['agent']);
        }

        foreach ($selected['rules'] as $rule) {
            if ($rule->matches($path)) {
                return new MatchResult($rule->allow, $rule, $selected['agent']);
            }
        }

        return new MatchResult(true, null, $selected['agent']);
    }

    /**
     * Does a normalised pattern match a normalised path?
     */
    public static function matches(string $pattern, string $path): bool
    {
        return (new Rule(false, $pattern))->matches($path);
    }
}
