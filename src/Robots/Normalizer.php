<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Robots;

/**
 * Percent-encoding normalisation shared by patterns and paths (RFC 9309 2.2.2).
 *
 * Both sides of a comparison go through the same rules:
 *  - escapes of unreserved characters (A-Z a-z 0-9 - . _ ~) are decoded;
 *  - every other escape keeps its encoding, with the hex digits uppercased;
 *  - octets outside printable ASCII, and a "%" that does not start a valid
 *    escape, are percent-encoded.
 *
 * Patterns keep "*" as a wildcard and a final "$" as an end anchor. A "$"
 * anywhere else in a pattern is literal and becomes "%24". On the path side
 * a real "*" or "$" becomes "%2A" or "%24", so it can only be matched by a
 * pattern that writes the escape (RFC 9309 2.2.3).
 *
 * This class has no WordPress dependency.
 */
final class Normalizer
{
    public static function pattern(string $pattern): string
    {
        $anchored = str_ends_with($pattern, '$');
        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }
        $pattern = self::encode($pattern);
        if (str_contains($pattern, '$')) {
            $pattern = str_replace('$', '%24', $pattern);
        }
        if (str_contains($pattern, '**')) {
            // Several "*" in a row behave like one (M6).
            $pattern = (string) preg_replace('/\*{2,}/', '*', $pattern);
        }

        return $anchored ? $pattern . '$' : $pattern;
    }

    public static function path(string $path): string
    {
        $path = self::encode($path);
        if (strpbrk($path, '*$') === false) {
            return $path;
        }

        return str_replace(['*', '$'], ['%2A', '%24'], $path);
    }

    private static function encode(string $value): string
    {
        // PHP's strspn() is a nested loop over the mask; a fixed character
        // class is one linear scan.
        $hasUnsafe = preg_match('/[^\x21-\x7E]/', $value) === 1;
        if (!$hasUnsafe && !str_contains($value, '%')) {
            return $value;
        }
        if ($hasUnsafe) {
            $value = (string) preg_replace_callback(
                '/[^\x21-\x7E]/',
                static fn (array $m): string => '%' . strtoupper(bin2hex($m[0])),
                $value
            );
        }
        if (!str_contains($value, '%')) {
            return $value;
        }

        // Escapes: decode unreserved, uppercase the rest, and encode a "%"
        // that does not start a valid escape as "%25". One regex pass with a
        // table lookup; the pattern is fixed and cannot backtrack. Octets
        // encoded above are never unreserved, so a second pass over them is
        // harmless.
        return (string) preg_replace_callback(
            '/%(?:[0-9A-Fa-f]{2})?/',
            static fn (array $m): string => self::escapes()[$m[0]] ?? '%25',
            $value
        );
    }

    /**
     * Every "%xx" escape, in any case, mapped to its normal form: the
     * character itself when unreserved, else "%XX" in upper case.
     *
     * @return array<string, string>
     */
    private static function escapes(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            for ($byte = 0; $byte < 256; $byte++) {
                $char = chr($byte);
                $upper = sprintf('%02X', $byte);
                $normal = self::isUnreserved($char) ? $char : '%' . $upper;
                foreach (array_unique([$upper, strtolower($upper), ucfirst(strtolower($upper)), strtolower($upper[0]) . $upper[1]]) as $hex) {
                    $map['%' . $hex] = $normal;
                }
            }
        }

        return $map;
    }

    private static function isUnreserved(string $char): bool
    {
        return ctype_alnum($char) || $char === '-' || $char === '.' || $char === '_' || $char === '~';
    }
}
