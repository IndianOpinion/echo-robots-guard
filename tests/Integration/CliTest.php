<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Tests\Integration;

use IndianOpinion\EchoRobotsGuard\Cli\Command;
use IndianOpinion\EchoRobotsGuard\EchoIntegration;
use IndianOpinion\EchoRobotsGuard\Tests\TestCase;

/**
 * WP-CLI: T92, T105.
 */
final class CliTest extends TestCase
{
    /**
     * @param resource|null $stdin
     */
    private function cli($stdin = null): Command
    {
        return new Command(
            $this->plugin->settings,
            $this->plugin->guard,
            $this->plugin->cache,
            $this->plugin->recorder,
            $stdin
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lastRows(): array
    {
        $last = end(\WP_CLI::$tables);

        return $last === false ? [] : $last['rows'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->configure(['mode' => 'enforce']);
        $this->robots('https://a.example', 'basic.txt');
    }

    public function testT92CheckReadsStdinAndExitsOneOnDisallow(): void
    {
        $stdin = fopen('php://memory', 'r+');
        self::assertIsResource($stdin);
        fwrite($stdin, "https://a.example/fine\n# a comment\n\nhttps://a.example/blocked\nhttps://a.example/also-fine\n");
        rewind($stdin);
        try {
            $this->cli($stdin)->check(['-'], []);
            self::fail('Expected exit status 1');
        } catch (\WpCliExit $e) {
            self::assertSame(1, $e->getCode());
        }
        $rows = $this->lastRows();
        self::assertCount(3, $rows);
        self::assertSame(['allowed', 'disallowed', 'allowed'], array_column($rows, 'result'));
        self::assertSame('User-agent: ExampleBot / Disallow: /blocked', $rows[1]['rule']);
    }

    public function testCheckExitsZeroWhenAllAllowedOrNotChecked(): void
    {
        $this->cli()->check(['https://a.example/fine', 'mailto:x@y'], []);
        self::assertNull(\WP_CLI::$exit);
        self::assertSame(['allowed', 'not checked'], array_column($this->lastRows(), 'result'));
    }

    public function testT105CheckJsonFreshToken(): void
    {
        $this->cli()->check(['https://a.example/other'], []);
        self::assertSame(1, $this->requestCount());
        $this->robotsBody('https://a.example', "User-agent: OtherBot\nDisallow: /\n");
        // A new "wp" process starts with no per-request state.
        $this->plugin->guard->resetRequest();
        try {
            $this->cli()->check(['https://a.example/other', 'https://a.example/x'], ['format' => 'json', 'fresh' => true, 'token' => 'OtherBot']);
            self::fail('Expected exit status 1');
        } catch (\WpCliExit $e) {
            self::assertSame(1, $e->getCode());
        }
        self::assertSame(2, $this->requestCount(), '--fresh refetches once per origin per run');
        $json = json_decode((string) end(\WP_CLI::$out), true);
        self::assertIsArray($json);
        self::assertSame('disallowed', $json[0]['result']);
        self::assertSame('OtherBot', $json[0]['token']);
        self::assertSame('miss', $json[0]['cache']);
    }

    public function testT105BadTokenIsAnError(): void
    {
        $this->expectException(\WpCliExit::class);
        $this->cli()->check(['https://a.example/x'], ['token' => 'no spaces allowed']);
    }

    public function testT105CacheListStatsLogStatus(): void
    {
        $this->plugin->boot();
        apply_filters(EchoIntegration::FILTER, 'https://a.example/blocked/1');
        apply_filters(EchoIntegration::FILTER, 'https://a.example/fine');
        for ($i = 2; $i <= 8; $i++) {
            apply_filters(EchoIntegration::FILTER, 'https://a.example/blocked/' . $i);
        }

        $this->cli()->cache(['list'], []);
        $rows = $this->lastRows();
        self::assertSame('https://a.example', $rows[0]['origin']);
        self::assertSame('ok', $rows[0]['outcome']);

        $this->cli()->log([], ['limit' => '5']);
        $rows = $this->lastRows();
        self::assertCount(5, $rows);
        self::assertSame('https://a.example/blocked/8', $rows[0]['url']);

        $this->cli()->stats([], []);
        $stats = [];
        foreach ($this->lastRows() as $row) {
            $stats[$row['context'] . '.' . $row['counter']] = $row['total'];
        }
        self::assertSame(8, $stats['echo.blocked']);
        self::assertSame(1, $stats['echo.allowed']);

        $this->cli()->status([], []);
        $status = array_column($this->lastRows(), 'value', 'setting');
        self::assertSame('enforce', $status['mode']);
        self::assertSame('ExampleBot', $status['token']);
        self::assertNotSame('never', $status['last_echo_call']);

        $this->plugin->recorder->flush();
        $this->cli()->stats([], ['reset' => true]);
        $this->cli()->stats([], []);
        self::assertSame([], $this->lastRows());
        $this->cli()->log([], []);
        self::assertSame([], $this->lastRows());
    }

    public function testCacheUnknownActionIsAnError(): void
    {
        $this->expectException(\WpCliExit::class);
        $this->cli()->cache(['explode'], []);
    }

    public function testCheckIsNotLimitedByTheFetchCap(): void
    {
        $this->configure(['mode' => 'enforce', 'max_fetches' => 1]);
        foreach (['b', 'c', 'd'] as $host) {
            $this->robotsBody("https://{$host}.example", "User-agent: *\nDisallow: /no\n");
        }
        $this->cli()->check(['https://b.example/ok', 'https://c.example/ok', 'https://d.example/ok'], []);
        self::assertSame(['allowed', 'allowed', 'allowed'], array_column($this->lastRows(), 'result'));
        self::assertSame(['no_rule', 'no_rule', 'no_rule'], array_column($this->lastRows(), 'reason'));
    }
}
