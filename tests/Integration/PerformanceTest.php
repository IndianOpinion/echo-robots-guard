<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Tests\Integration;

use IndianOpinion\EchoRobotsGuard\Robots\Parser;
use IndianOpinion\EchoRobotsGuard\Tests\Budget;
use IndianOpinion\EchoRobotsGuard\Tests\TestCase;

/**
 * Performance budget, PRD section 9: T107. Each timing is the best of a few
 * runs, so a noisy CI neighbour does not fail the build on one slow sample.
 */
final class PerformanceTest extends TestCase
{
    /**
     * @param callable(): mixed $fn
     */
    private static function bestMs(callable $fn, int $runs = 5): float
    {
        $best = PHP_FLOAT_MAX;
        for ($i = 0; $i < $runs; $i++) {
            $start = hrtime(true);
            $fn();
            $best = min($best, (hrtime(true) - $start) / 1e6);
        }

        return $best;
    }

    public function testT107CacheHitUnderOneMillisecond(): void
    {
        $this->configure([]);
        $body = "User-agent: *\n";
        for ($i = 0; $i < 90; $i++) {
            $body .= "Disallow: /section-{$i}/*?print=\n";
        }
        $body .= "User-agent: ExampleBot\nDisallow: /private\nAllow: /private/public\n";
        $this->robotsBody('https://a.example', $body);
        $this->plugin->mayFetch('https://a.example/warm');

        $ms = self::bestMs(function (): void {
            // A new request each time: the answer comes from the object cache.
            $this->plugin->guard->resetRequest();
            $this->plugin->mayFetch('https://a.example/news/2026/11/11/story-slug?ref=feed');
        }, 20);
        self::assertSame(1, $this->requestCount());
        self::assertLessThan(1.0 * Budget::factor(), $ms, "Cache hit took {$ms} ms");
    }

    public function testT107Parse500KiBUnder50Milliseconds(): void
    {
        $body = "User-agent: *\n";
        $i = 0;
        while (strlen($body) < Parser::MIN_BYTES) {
            $body .= $i % 4 === 0
                ? "# comment line number {$i}\n"
                : "Disallow: /archive/{$i}/caf%c3%a9/*.php\$\n";
            $i++;
        }
        $parser = new Parser();
        $ms = self::bestMs(static fn () => $parser->parse($body), 3);
        self::assertLessThan(50.0 * Budget::factor(), $ms, "Parse of 500 KiB took {$ms} ms");
    }
}
