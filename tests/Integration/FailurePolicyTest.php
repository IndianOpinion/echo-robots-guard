<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Tests\Integration;

use IndianOpinion\EchoRobotsGuard\Decision;
use IndianOpinion\EchoRobotsGuard\EchoIntegration;
use IndianOpinion\EchoRobotsGuard\Tests\TestCase;

/**
 * Failure policy: T87 to T90, and the per-request fetch cap (decision 6).
 */
final class FailurePolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configure(['mode' => 'enforce']);
        $this->plugin->boot();
        \WpStub::respond('https://a.example/robots.txt', 503);
    }

    public function testT87FailClosed(): void
    {
        self::assertTrue(apply_filters(EchoIntegration::FILTER, 'https://a.example/story'));
        self::assertSame('unreachable, fail closed', $this->plugin->recorder->entries()[0]['rule']);
    }

    public function testT88FailOpen(): void
    {
        $this->configure(['fail_policy' => 'open']);
        $url = 'https://a.example/story';
        self::assertSame($url, apply_filters(EchoIntegration::FILTER, $url));
        $decision = $this->plugin->mayFetch($url);
        self::assertTrue($decision->allowed);
        self::assertSame(Decision::UNREACHABLE, $decision->reason);
        $texts = implode("\n", array_column($this->plugin->admin->notices(), 'text'));
        self::assertStringContainsString('Fail open is in use', $texts);
    }

    public function testT89UnreachableCachedUnderBothPolicies(): void
    {
        foreach (['closed', 'open'] as $policy) {
            \WpStub::reset();
            $this->plugin = $this->makePlugin();
            $this->configure(['fail_policy' => $policy]);
            \WpStub::respond('https://a.example/robots.txt', 503);
            $this->plugin->mayFetch('https://a.example/1');
            $this->clock->advance(1800);
            $this->plugin->guard->resetRequest();
            $decision = $this->plugin->mayFetch('https://a.example/2');
            self::assertSame(1, $this->requestCount(), $policy);
            self::assertSame('hit', $decision->cache);
            self::assertSame($policy === 'open', $decision->allowed);
        }
    }

    public function testT90LongUnreachableListed(): void
    {
        $this->plugin->mayFetch('https://a.example/1');
        for ($day = 1; $day <= 8; $day++) {
            $this->clock->advance(86400);
            $this->plugin->guard->resetRequest();
            $decision = $this->plugin->mayFetch('https://a.example/1');
            self::assertFalse($decision->allowed);
        }
        $long = $this->plugin->admin->longUnreachable();
        self::assertCount(1, $long);
        self::assertSame('https://a.example', $long[0]->origin);

        ob_start();
        $this->plugin->admin->render();
        $html = (string) ob_get_clean();
        self::assertStringContainsString('Unreachable for more than 7 days', $html);
    }

    public function testRecoveryClearsUnreachableSince(): void
    {
        $this->plugin->mayFetch('https://a.example/1');
        \WpStub::$http['https://a.example/robots.txt'] = [];
        \WpStub::respond('https://a.example/robots.txt', 200, '');
        $this->clock->advance(8 * 86400);
        $this->plugin->guard->resetRequest();
        self::assertTrue($this->plugin->mayFetch('https://a.example/1')->allowed);
        self::assertSame([], $this->plugin->admin->longUnreachable());
    }

    public function testFetchCapUsesPolicyAndDoesNotCache(): void
    {
        $this->configure(['max_fetches' => 2]);
        foreach (['b', 'c', 'd'] as $host) {
            \WpStub::respond("https://{$host}.example/robots.txt", 200, '');
        }
        self::assertTrue($this->plugin->mayFetch('https://b.example/x')->allowed);
        self::assertTrue($this->plugin->mayFetch('https://c.example/x')->allowed);
        $capped = $this->plugin->mayFetch('https://d.example/x');
        self::assertFalse($capped->allowed);
        self::assertSame(Decision::FETCH_CAP, $capped->reason);
        self::assertSame('fetch cap reached, fail closed', $capped->ruleText());
        self::assertSame(2, $this->requestCount());
        self::assertSame(1, $this->today('api')['fetch_cap_reached']);

        // Cached origins still answer past the cap.
        self::assertSame('hit', $this->plugin->mayFetch('https://b.example/y')->cache);

        // The next request fetches the capped origin normally.
        $this->plugin->guard->resetRequest();
        self::assertTrue($this->plugin->mayFetch('https://d.example/x')->allowed);
    }

    public function testFetchCapDefaultIsTwenty(): void
    {
        self::assertSame(20, $this->plugin->settings->maxFetches());
        add_filter('echo_robots_guard_max_fetches_per_request', static fn (): int => 50);
        self::assertSame(50, $this->plugin->settings->maxFetches());
    }

    public function testFetchCapPrefersStaleCopy(): void
    {
        $this->configure(['max_fetches' => 1]);
        \WpStub::respond('https://b.example/robots.txt', 200, "User-agent: *\nDisallow: /old\n");
        $this->plugin->mayFetch('https://b.example/x');
        $this->clock->advance(2 * 86400);
        $this->plugin->guard->resetRequest();
        $this->plugin->mayFetch('https://a.example/x');
        $decision = $this->plugin->mayFetch('https://b.example/new');
        self::assertSame(Decision::STALE_COPY, $decision->reason);
        self::assertTrue($decision->allowed);
        self::assertFalse($this->plugin->mayFetch('https://b.example/old')->allowed);
    }
}
