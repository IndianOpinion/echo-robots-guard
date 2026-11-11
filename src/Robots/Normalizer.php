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
        $hasUnsafe = strspn($value, self::printable()) !== strlen($value);
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
        // that does not start a valid escape. Octets encoded above are never
        // unreserved, so a second pass over them is harmless.
        $parts = explode('%', $value);
        $out = $parts[0];
        $count = count($parts);
        for ($i = 1; $i < $count; $i++) {
            $part = $parts[$i];
            $hex = substr($part, 0, 2);
            if (strlen($hex) === 2 && ctype_xdigit($hex)) {
                $char = chr((int) hexdec($hex));
                $out .= (self::isUnreserved($char) ? $char : '%' . strtoupper($hex)) . substr($part, 2);
            } else {
                $out .= '%25' . $part;
            }
        }

        return $out;
    }

    /**
     * Printable ASCII, 0x21 to 0x7E: the octets that are never encoded.
     */
    private static function printable(): string
    {
        static $set = null;

        return $set ??= implode('', array_map('chr', range(0x21, 0x7E)));
    }

    private static function isUnreserved(string $char): bool
    {
        return ctype_alnum($char) || $char === '-' || $char === '.' || $char === '_' || $char === '~';
    }
}
