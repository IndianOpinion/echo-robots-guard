<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Tests\Integration;

use IndianOpinion\EchoRobotsGuard\Decision;
use IndianOpinion\EchoRobotsGuard\Plugin;
use IndianOpinion\EchoRobotsGuard\Store\TableStore;
use IndianOpinion\EchoRobotsGuard\Tests\Fixtures;
use IndianOpinion\EchoRobotsGuard\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Fetching robots.txt and HTTP status: T42 to T59.
 */
final class HttpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configure([]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rows(): array
    {
        return $GLOBALS['wpdb']->tables[TableStore::tableName()] ?? [];
    }

    public function testT42SchemeAndHostMakeDistinctOrigins(): void
    {
        foreach (['https://a.example', 'http://a.example', 'https://www.a.example'] as $origin) {
            Fixtures::http($origin . '/robots.txt', '200-basic');
        }
        $this->plugin->mayFetch('https://a.example/x');
        $this->plugin->mayFetch('http://a.example/x');
        $this->plugin->mayFetch('https://www.a.example/x');
        self::assertSame(3, $this->requestCount());
        self::assertCount(3, $this->rows());
    }

    public function testT43DefaultPortAndHostCase(): void
    {
        Fixtures::http('https://a.example/robots.txt', '200-basic');
        $first = $this->plugin->mayFetch('https://A.Example:443/x');
        $second = $this->plugin->mayFetch('https://a.example/y');
        self::assertSame(1, $this->requestCount());
        self::assertSame('https://a.example', $first->origin);
        self::assertSame('hit', $second->cache);
        $this->plugin->mayFetch('http://a.example:80/z');
        self::assertSame('http://a.example/robots.txt', \WpStub::requestedUrls()[1]);
    }

    public function testT44UserinfoNotSentAndIpv6Kept(): void
    {
        \WpStub::respond('https://[2606:4700::1]:8443/robots.txt', 200, "User-agent: *\nDisallow: /x\n");
        $decision = $this->plugin->mayFetch('https://user:secret@[2606:4700::1]:8443/x');
        self::assertSame(['https://[2606:4700::1]:8443/robots.txt'], \WpStub::requestedUrls());
        self::assertSame('https://[2606:4700::1]:8443', $decision->origin);
        self::assertFalse($decision->allowed);
        self::assertStringNotContainsString('secret', (string) json_encode(\WpStub::$requests));
    }

    public function testT45RulesApplied(): void
    {
        Fixtures::http('https://a.example/robots.txt', '200-basic');
        $decision = $this->plugin->mayFetch('https://a.example/blocked/story');
        self::assertFalse($decision->allowed);
        self::assertSame(Decision::RULE, $decision->reason);
        self::assertSame(['agent' => 'ExampleBot', 'directive' => 'disallow', 'pattern' => '/blocked'], $decision->rule);
        self::assertTrue($this->plugin->mayFetch('https://a.example/fine')->allowed);
    }

    public function testT46EmptyBodyAllows(): void
    {
        Fixtures::http('https://a.example/robots.txt', '200-empty');
        $decision = $this->plugin->mayFetch('https://a.example/x');
        self::assertTrue($decision->allowed);
        self::assertSame(Decision::NO_RULE, $decision->reason);
    }

    public function testT47RedirectsAcrossHostsApplyToOriginalOrigin(): void
    {
        \WpStub::respond('https://a.example/robots.txt', 301, '', ['Location' => 'https://b.example/one']);
        \WpStub::respond('https://b.example/one', 302, '', ['Location' => '//c.example/two']);
        \WpStub::respond('https://c.example/two', 307, '', ['Location' => 'three.txt']);
        Fixtures::http('https://c.example/three.txt', '200-disallow-all');

        $decision = $this->plugin->mayFetch('https://a.example/story');
        self::assertFalse($decision->allowed);
        self::assertSame('https://a.example', $decision->origin);
        self::assertCount(4, \WpStub::$requests);
        $origins = array_map(static fn (array $row): string => (string) $row['origin'], array_values($this->rows()));
        self::assertSame(['https://a.example'], $origins);
        foreach (\WpStub::$requests as $request) {
            self::assertSame(0, $request['args']['redirection']);
        }
    }

    public function testT48TooManyRedirectsIsUnavailable(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $from = $i === 0 ? 'https://a.example/robots.txt' : 'https://a.example/r' . $i;
            \WpStub::respond($from, 301, '', ['Location' => '/r' . ($i + 1)]);
        }
        $decision = $this->plugin->mayFetch('https://a.example/story');
        self::assertTrue($decision->allowed);
        self::assertSame(Decision::UNAVAILABLE, $decision->reason);
        self::assertCount(6, \WpStub::$requests);
        self::assertSame(1, $this->today('api')['fetch_error_redirect_limit'] ?? 0);
    }

    public function testT49RedirectToFtpIsUnreachable(): void
    {
        \WpStub::respond('https://a.example/robots.txt', 301, '', ['Location' => 'ftp://a.example/robots.txt']);
        $decision = $this->plugin->mayFetch('https://a.example/story');
        self::assertFalse($decision->allowed);
        self::assertSame(Decision::UNREACHABLE, $decision->reason);
        self::assertCount(1, \WpStub::$requests);
        self::assertSame(1, $this->today('api')['fetch_error_redirect_scheme'] ?? 0);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function clientErrors(): array
    {
        return ['401' => [401], '403' => [403], '404' => [404], '410' => [410]];
    }

    #[DataProvider('clientErrors')]
    public function testT50ClientErrorsAllowAll(int $status): void
    {
        $this->robotsBody('https://a.example', "User-agent: *\nDisallow: /\n", $status);
        $decision = $this->plugin->mayFetch('https://a.example/story');
        self::assertTrue($decision->allowed);
        self::assertSame(Decision::UNAVAILABLE, $decision->reason);
    }

    public function testT51TooManyRequestsIsUnreachable(): void
    {
        Fixtures::http('https://a.example/robots.txt', '429');
        $decision = $this->plugin->mayFetch('https://a.example/story');
        self::assertFalse($decision->allowed);
        self::assertSame(Decision::UNREACHABLE, $decision->reason);
        self::assertSame(1, $this->today('api')['fetch_error_429'] ?? 0);
    }

    /**
     * @return array<string, array{0: callable(string): void, 1: string}>
     */
    public static function unreachable(): array
    {
        return [
            '500' => [static fn (string $u) => \WpStub::respond($u, 500), '5xx'],
            '503' => [static fn (string $u) => Fixtures::http($u, '503'), '5xx'],
            'timeout' => [static fn (string $u) => \WpStub::fail($u, 'cURL error 28: Operation timed out after 8001 milliseconds'), 'timeout'],
            'dns' => [static fn (string $u) => \WpStub::fail($u, 'cURL error 6: Could not resolve host: a.example'), 'dns'],
            'tls' => [static fn (string $u) => \WpStub::fail($u, 'cURL error 60: SSL certificate problem: unable to get local issuer certificate'), 'tls'],
            'refused' => [static fn (string $u) => \WpStub::fail($u, 'cURL error 7: Failed to connect: Connection refused'), 'refused'],
            'status 999' => [static fn (string $u) => Fixtures::http($u, '999'), 'status'],
        ];
    }

    /**
     * @param callable(string): void $arrange
     */
    #[DataProvider('unreachable')]
    public function testT52UnreachableOutcomes(callable $arrange, string $class): void
    {
        $arrange('https://a.example/robots.txt');
        $decision = $this->plugin->mayFetch('https://a.example/story');
        self::assertFalse($decision->allowed);
        self::assertSame(Decision::UNREACHABLE, $decision->reason);
        self::assertSame('unreachable, fail closed', $decision->ruleText());
        $today = $this->today('api');
        self::assertSame(1, $today['fetch_errors'] ?? 0);
        self::assertSame(1, $today['fetch_error_' . $class] ?? 0);
    }

    public function testT53StaleCopyUsedWhileUnreachable(): void
    {
        $this->robots('https://a.example', 'basic.txt');
        \WpStub::respond('https://a.example/robots.txt', 503);
        self::assertFalse($this->plugin->mayFetch('https://a.example/blocked')->allowed);

        $this->clock->advance(86400 + 1);
        $this->plugin->guard->resetRequest();
        $decision = $this->plugin->mayFetch('https://a.example/blocked');
        self::assertSame(2, $this->requestCount());
        self::assertFalse($decision->allowed);
        self::assertSame(Decision::STALE_COPY, $decision->reason);
        self::assertSame('stale', $decision->cache);
        self::assertTrue($this->plugin->mayFetch('https://a.example/open')->allowed);
        self::assertSame(2, $this->today('api')['stale_used'] ?? 0);
    }

    public function testT54StaleCopyPastWindowFallsToPolicy(): void
    {
        $this->robots('https://a.example', 'basic.txt');
        \WpStub::respond('https://a.example/robots.txt', 503);
        $this->plugin->mayFetch('https://a.example/open');

        $this->clock->advance(31 * 86400);
        $this->plugin->guard->resetRequest();
        $decision = $this->plugin->mayFetch('https://a.example/open');
        self::assertFalse($decision->allowed);
        self::assertSame(Decision::UNREACHABLE, $decision->reason);
    }

    public function testT55TimeoutPassedToHttpApi(): void
    {
        $this->configure(['timeout' => 3]);
        $this->plugin->mayFetch('https://a.example/x');
        self::assertSame(3, \WpStub::$requests[0]['args']['timeout']);
    }

    public function testT56UserAgentAndNoCookies(): void
    {
        $this->plugin->mayFetch('https://a.example/x');
        $args = \WpStub::$requests[0]['args'];
        self::assertSame('ExampleBot/' . Plugin::VERSION . ' (+https://site.example/bot)', $args['user-agent']);
        self::assertSame([], $args['cookies']);
        self::assertArrayNotHasKey('Cookie', $args['headers']);
        self::assertArrayNotHasKey('Referer', $args['headers']);
        self::assertTrue($args['sslverify']);
        self::assertTrue(\WpStub::$requests[0]['safe']);
    }

    public function testT57UnsafeDestinationsRefused(): void
    {
        foreach (['http://127.0.0.1/x', 'http://169.254.169.254/latest', 'http://10.0.0.5/x', 'http://[::1]/x', 'http://localhost/x'] as $url) {
            $decision = $this->plugin->mayFetch($url);
            self::assertFalse($decision->allowed, $url);
            self::assertSame(Decision::UNREACHABLE, $decision->reason, $url);
        }
        self::assertSame([], \WpStub::$requests);
        self::assertSame(5, $this->today('api')['fetch_error_unsafe'] ?? 0);

        add_filter('echo_robots_guard_allowed_internal_hosts', static fn (): array => ['10.0.0.5']);
        $this->plugin->guard->resetRequest();
        $this->clock->advance(7200);
        \WpStub::respond('http://10.0.0.5/robots.txt', 200, '');
        self::assertTrue($this->plugin->mayFetch('http://10.0.0.5/x')->allowed);
        self::assertSame(['http://10.0.0.5/robots.txt'], \WpStub::requestedUrls());
        self::assertFalse(\WpStub::$requests[0]['safe']);
    }

    public function testT57HostNamesResolvingInternallyRefusedByWordPress(): void
    {
        \WpStub::$unsafeHosts = ['intranet.example'];
        $decision = $this->plugin->mayFetch('https://intranet.example/x');
        self::assertSame(Decision::UNREACHABLE, $decision->reason);
        self::assertSame(1, $this->today('api')['fetch_error_unsafe'] ?? 0);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function notUrls(): array
    {
        return [
            'ftp' => ['ftp://x'],
            'mailto' => ['mailto:a@b'],
            'relative' => ['/relative'],
            'empty' => [''],
            'no host' => ['https://'],
        ];
    }

    #[DataProvider('notUrls')]
    public function testT58NonHttpUrlsNotChecked(string $url): void
    {
        $decision = $this->plugin->mayFetch($url);
        self::assertNull($decision->allowed);
        self::assertSame(Decision::NOT_HTTP_URL, $decision->reason);
        self::assertSame([], \WpStub::$requests);
    }

    public function testT59OneFetchPerOriginWhenCacheWritesFail(): void
    {
        $GLOBALS['wpdb']->failWrites = true;
        \WpStub::$objectCache = false;
        Fixtures::http('https://a.example/robots.txt', '200-basic');
        for ($i = 0; $i < 5; $i++) {
            self::assertFalse($this->plugin->mayFetch('https://a.example/blocked/' . $i)->allowed);
        }
        self::assertSame(1, $this->requestCount());
        self::assertSame(1, $this->today('api')['cache_write_errors'] ?? 0);
    }

    public function testFetchErrorsLoggedOncePerOriginPerDay(): void
    {
        \WpStub::respond('https://a.example/robots.txt', 503);
        $this->plugin->mayFetch('https://a.example/x');
        for ($i = 0; $i < 3; $i++) {
            $this->clock->advance(3601);
            $this->plugin->guard->resetRequest();
            $this->plugin->mayFetch('https://a.example/x');
        }
        self::assertSame(4, $this->requestCount());
        self::assertCount(1, $this->logLines);
        self::assertStringStartsWith('[echo-robots-guard] https://a.example/robots.txt: fetch error "5xx"', $this->logLines[0]);
    }
}
