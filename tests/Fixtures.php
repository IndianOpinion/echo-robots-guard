<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Tests;

final class Fixtures
{
    public static function robots(string $name): string
    {
        $body = file_get_contents(__DIR__ . '/fixtures/robots/' . $name);
        if ($body === false) {
            throw new \RuntimeException('Missing fixture ' . $name);
        }

        return $body;
    }

    /**
     * Queues the response described by tests/fixtures/http/<name>.json for a URL.
     */
    public static function http(string $url, string $name): void
    {
        $raw = file_get_contents(__DIR__ . '/fixtures/http/' . $name . '.json');
        $spec = json_decode((string) $raw, true);
        if (!is_array($spec)) {
            throw new \RuntimeException('Bad HTTP fixture ' . $name);
        }
        $body = is_string($spec['body'] ?? null) ? self::robots($spec['body']) : '';
        \WpStub::respond($url, (int) $spec['status'], $body, (array) ($spec['headers'] ?? []));
    }
}
