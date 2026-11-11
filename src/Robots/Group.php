<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Robots;

/**
 * A robots.txt group: one or more user agents and the rules that follow them.
 */
final class Group
{
    /**
     * @param list<string> $agents Product tokens as written, or "*".
     * @param list<Rule>   $rules
     */
    public function __construct(
        public readonly array $agents,
        public readonly array $rules
    ) {
    }

    /**
     * Exact, case-insensitive product token match (G1, G2). "*" is not
     * matched here; the caller decides the wildcard fallback.
     */
    public function names(string $token): ?string
    {
        foreach ($this->agents as $agent) {
            if ($agent !== '*' && strcasecmp($agent, $token) === 0) {
                return $agent;
            }
        }

        return null;
    }

    public function isWildcard(): bool
    {
        return in_array('*', $this->agents, true);
    }
}
