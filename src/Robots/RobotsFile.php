<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Robots;

/**
 * The parsed form of one robots.txt file: every group, plus what the parser
 * had to drop or tolerate. Serialisable to a plain array for caching.
 */
final class RobotsFile
{
    /** @var array<string, array{agent: ?string, rules: list<Rule>}> Selected rules by lowercased token. */
    private array $selections = [];

    /**
     * @param list<Group>  $groups
     * @param list<string> $misspellings Distinct misspelt keys that were accepted.
     */
    public function __construct(
        public readonly array $groups,
        public readonly int $bytesRead = 0,
        public readonly bool $truncated = false,
        public readonly int $rulesDropped = 0,
        public readonly array $misspellings = []
    ) {
    }

    /**
     * The rules that apply to a token, sorted for matching: longest first,
     * Allow before Disallow on equal length. Memoised per token.
     *
     * @return array{agent: ?string, rules: list<Rule>}
     */
    public function selection(string $token): array
    {
        $key = strtolower($token);
        if (!isset($this->selections[$key])) {
            $selected = Matcher::rulesFor($this, $token);
            $rules = $selected['rules'];
            usort($rules, static function (Rule $a, Rule $b): int {
                return [$b->length(), $b->allow] <=> [$a->length(), $a->allow];
            });
            $this->selections[$key] = ['agent' => $selected['agent'], 'rules' => $rules];
        }

        return $this->selections[$key];
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @return array{g: list<array{a: list<string>, r: list<array{0: int, 1: string}>}>}
     */
    public function toArray(): array
    {
        $groups = [];
        foreach ($this->groups as $group) {
            $rules = [];
            foreach ($group->rules as $rule) {
                $rules[] = [$rule->allow ? 1 : 0, $rule->pattern];
            }
            $groups[] = ['a' => $group->agents, 'r' => $rules];
        }

        return ['g' => $groups];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $groups = [];
        $rawGroups = $data['g'] ?? [];
        if (!is_array($rawGroups)) {
            return self::empty();
        }
        foreach ($rawGroups as $rawGroup) {
            if (!is_array($rawGroup) || !is_array($rawGroup['a'] ?? null) || !is_array($rawGroup['r'] ?? null)) {
                continue;
            }
            $agents = [];
            foreach ($rawGroup['a'] as $agent) {
                if (is_string($agent)) {
                    $agents[] = $agent;
                }
            }
            $rules = [];
            foreach ($rawGroup['r'] as $rawRule) {
                if (is_array($rawRule) && isset($rawRule[0], $rawRule[1]) && is_string($rawRule[1])) {
                    $rules[] = new Rule((bool) $rawRule[0], $rawRule[1]);
                }
            }
            $groups[] = new Group($agents, $rules);
        }

        return new self($groups);
    }
}
