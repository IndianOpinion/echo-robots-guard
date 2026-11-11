<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Robots;

/**
 * One Allow or Disallow rule, with its pattern already normalised.
 */
final class Rule
{
    /**
     * The pattern split on "*": the leading piece, the middle pieces, the
     * last piece (null when there is no "*"), and whether it ends in "$".
     *
     * @var array{0: string, 1: list<string>, 2: ?string, 3: bool}|null
     */
    private ?array $compiled = null;

    public function __construct(
        public readonly bool $allow,
        public readonly string $pattern
    ) {
    }

    public function directive(): string
    {
        return $this->allow ? 'allow' : 'disallow';
    }

    /**
     * Length used for "longest match wins" (M3): octets of the normalised
     * pattern, counting "*" and "$" as written.
     */
    public function length(): int
    {
        return strlen($this->pattern);
    }

    /**
     * Does this rule match a normalised path? Linear: each piece between
     * "*" is found once, left to right, with no backtracking (M15).
     */
    public function matches(string $path): bool
    {
        [$first, $middle, $tail, $anchored] = $this->compiled ??= self::compile($this->pattern);

        if ($first !== '' && strncmp($path, $first, strlen($first)) !== 0) {
            return false;
        }
        if ($tail === null) {
            return $anchored ? $path === $first : true;
        }

        $cursor = strlen($first);
        if ($tail !== '' && strpos($path, $tail, $cursor) === false) {
            // Cheap early exit: the last piece is nowhere after the prefix.
            return false;
        }
        foreach ($middle as $piece) {
            if ($piece === '') {
                continue;
            }
            $found = strpos($path, $piece, $cursor);
            if ($found === false) {
                return false;
            }
            $cursor = $found + strlen($piece);
        }

        if (!$anchored) {
            return $tail === '' || strpos($path, $tail, $cursor) !== false;
        }
        $tailLength = strlen($tail);
        if (strlen($path) - $tailLength < $cursor) {
            return false;
        }

        return $tailLength === 0 || substr_compare($path, $tail, -$tailLength) === 0;
    }

    /**
     * @return array{0: string, 1: list<string>, 2: ?string, 3: bool}
     */
    private static function compile(string $pattern): array
    {
        $anchored = str_ends_with($pattern, '$');
        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }
        $pieces = explode('*', $pattern);
        $first = array_shift($pieces);
        $tail = array_pop($pieces);

        return [$first, $pieces, $tail, $anchored];
    }
}
