<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Tests\Integration;

use IndianOpinion\EchoRobotsGuard\Decision;
use IndianOpinion\EchoRobotsGuard\EchoIntegration;
use IndianOpinion\EchoRobotsGuard\Exception\InvalidTokenException;
use IndianOpinion\EchoRobotsGuard\Plugin;
use IndianOpinion\EchoRobotsGuard\Robots\Parser;
use IndianOpinion\EchoRobotsGuard\Robots\RobotsFile;
use IndianOpinion\EchoRobotsGuard\Tests\TestCase;

use function IndianOpinion\EchoRobotsGuard\may_fetch;

/**
 * General API and hooks: T99 to T102, T106.
 */
final class ApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configure(['mode' => 'enforce', 'skip_hosts' => ['skip.example']]);
        $this->plugin->boot();
        $this->robots('https://a.example', 'basic.txt');
        $this->robotsBody('https://gone.example', '', 404);
        \WpStub::respond('https://down.example/robots.txt', 503);
    }

    public function testT99EveryReason(): void
    {
        $blocked = may_fetch('https://a.example/blocked');
        self::assertFalse($blocked->allowed);
        self::assertSame(Decision::RULE, $blocked->reason);
        self::assertSame('disallow', $blocked->rule['directive'] ?? null);

        $allowed = may_fetch('https://a.example/fine');
        self::assertTrue($allowed->allowed);
        self::assertSame(Decision::NO_RULE, $allowed->reason);
        self::assertNull($allowed->rule);

        self::assertSame(Decision::ROBOTS_TXT, may_fetch('https://a.example/robots.txt')->reason);

        $unavailable = may_fetch('https://gone.example/x');
        self::assertTrue($unavailable->allowed);
        self::assertSame(Decision::UNAVAILABLE, $unavailable->reason);

        $unreachable = may_fetch('https://down.example/x');
        self::assertFalse($unreachable->allowed);
        self::assertSame(Decision::UNREACHABLE, $unreachable->reason);

        $skip = may_fetch('https://skip.example/x');
        self::assertNull($skip->allowed);
        self::assertSame(Decision::SKIP_LIST, $skip->reason);

        $notUrl = may_fetch('not a url');
        self::assertNull($notUrl->allowed);
        self::assertSame(Decision::NOT_HTTP_URL, $notUrl->reason);

        foreach ([$blocked, $allowed, $unavailable, $unreachable, $skip, $notUrl] as $decision) {
            self::assertContains($decision->reason, Decision::REASONS);
        }
    }

    public function testT99StaleAndInternalError(): void
    {
        may_fetch('https://a.example/fine');
        $this->clock->advance(86401);
        $this->plugin->guard->resetRequest();
        \WpStub::$http['https://a.example/robots.txt'] = [];
        \WpStub::respond('https://a.example/robots.txt', 503);
        $stale = may_fetch('https://a.example/blocked');
        self::assertFalse($stale->allowed);
        self::assertSame(Decision::STALE_COPY, $stale->reason);
        self::assertSame('/blocked', $stale->rule['pattern'] ?? null);

        \WpStub::reset();
        $broken = new class () extends Parser {
            public function parse(string $body): RobotsFile
            {
                throw new \RuntimeException('boom');
            }
        };
        $this->plugin = $this->makePlugin($broken);
        Plugin::setInstance($this->plugin);
        $this->configure([]);
        $this->robots('https://a.example', 'basic.txt');
        $error = may_fetch('https://a.example/blocked');
        self::assertTrue($error->allowed);
        self::assertSame(Decision::INTERNAL_ERROR, $error->reason);
        self::assertSame(1, $this->today('api')['internal_errors']);
    }

    public function testT100FilterAnswersAndNullWhenInactive(): void
    {
        self::assertFalse(apply_filters('echo_robots_guard_may_fetch', null, 'https://a.example/blocked'));
        self::assertTrue(apply_filters('echo_robots_guard_may_fetch', null, 'https://a.example/fine'));
        self::assertNull(apply_filters('echo_robots_guard_may_fetch', null, 'https://skip.example/x'));
        self::assertNull(apply_filters('echo_robots_guard_may_fetch', null, 'mailto:a@b'));
        self::assertTrue(apply_filters('echo_robots_guard_may_fetch', null, 'https://a.example/other', 'ExampleBot'));
        self::assertFalse(apply_filters('echo_robots_guard_may_fetch', null, 'https://a.example/other', 'OtherBot'));

        remove_all_filters('echo_robots_guard_may_fetch');
        self::assertNull(apply_filters('echo_robots_guard_may_fetch', null, 'https://a.example/blocked'));
    }

    public function testT101OtherToken(): void
    {
        $decision = may_fetch('https://a.example/other', 'OtherBot');
        self::assertFalse($decision->allowed);
        self::assertSame('OtherBot', $decision->token);
        self::assertSame('OtherBot', $decision->rule['agent'] ?? null);
        self::assertTrue(may_fetch('https://a.example/blocked', 'OtherBot')->allowed);
    }

    public function testInvalidTokenThrows(): void
    {
        $this->expectException(InvalidTokenException::class);
        may_fetch('https://a.example/x', 'Bad Token!');
    }

    public function testT102EchoAndApiCountersApart(): void
    {
        apply_filters(EchoIntegration::FILTER, 'https://a.example/blocked');
        may_fetch('https://a.example/fine');
        $echo = $this->today('echo');
        $api = $this->today('api');
        self::assertSame(1, $echo['checked']);
        self::assertSame(1, $echo['blocked']);
        self::assertSame(1, $api['checked']);
        self::assertSame(1, $api['allowed']);
        self::assertArrayNotHasKey('blocked', $api);
    }

    public function testT106CountersFilterAndActions(): void
    {
        $decisions = [];
        $fetched = [];
        $blocked = [];
        add_action('echo_robots_guard_decision', static function (string $url, Decision $d, string $context) use (&$decisions): void {
            $decisions[] = [$url, $d->allowed, $context];
        }, 10, 3);
        add_action('echo_robots_guard_fetched', static function (string $origin, string $outcome, ?int $status, int $bytes) use (&$fetched): void {
            $fetched[] = [$origin, $outcome, $status, $bytes];
        }, 10, 4);
        add_action('echo_robots_guard_blocked', static function (string $url, mixed $rule, string $mode) use (&$blocked): void {
            $blocked[] = [$url, $mode];
        }, 10, 3);

        apply_filters(EchoIntegration::FILTER, 'https://a.example/blocked');
        may_fetch('https://a.example/fine');

        self::assertSame([
            ['https://a.example/blocked', false, 'echo'],
            ['https://a.example/fine', true, 'api'],
        ], $decisions);
        self::assertSame('https://a.example', $fetched[0][0]);
        self::assertSame('ok', $fetched[0][1]);
        self::assertSame(200, $fetched[0][2]);
        self::assertGreaterThan(0, $fetched[0][3]);
        self::assertSame([['https://a.example/blocked', 'enforce']], $blocked);

        $counters = apply_filters('echo_robots_guard_counters', []);
        self::assertSame(1, $counters['total']['echo']['blocked']);
        self::assertSame(1, $counters['total']['api']['allowed']);
    }

    public function testCountersFlushOnceAtShutdown(): void
    {
        for ($i = 0; $i < 50; $i++) {
            apply_filters(EchoIntegration::FILTER, 'https://a.example/blocked/' . $i);
        }
        self::assertArrayNotHasKey('echo_robots_guard_counters', \WpStub::$options[1]);
        do_action('shutdown');
        $stored = \WpStub::$options[1]['echo_robots_guard_counters'];
        self::assertSame(50, $stored['total']['echo']['blocked']);
        self::assertCount(50, \WpStub::$options[1]['echo_robots_guard_log']);
        for ($i = 0; $i < 70; $i++) {
            apply_filters(EchoIntegration::FILTER, 'https://a.example/blocked/more-' . $i);
        }
        do_action('shutdown');
        $log = \WpStub::$options[1]['echo_robots_guard_log'];
        self::assertCount(100, $log);
        self::assertSame('https://a.example/blocked/more-69', $log[0]['url']);
    }

    public function testDailyCountersKeepThirtyDays(): void
    {
        for ($day = 0; $day < 35; $day++) {
            may_fetch('https://a.example/fine');
            $this->plugin->recorder->flush();
            $this->clock->advance(86400);
        }
        $stored = \WpStub::$options[1]['echo_robots_guard_counters'];
        self::assertCount(30, $stored['days']);
        self::assertSame(35, $stored['total']['api']['checked']);
    }
}
