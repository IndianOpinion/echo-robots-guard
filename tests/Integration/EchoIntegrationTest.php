<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Tests\Integration;

use IndianOpinion\EchoRobotsGuard\EchoIntegration;
use IndianOpinion\EchoRobotsGuard\Plugin;
use IndianOpinion\EchoRobotsGuard\Robots\Parser;
use IndianOpinion\EchoRobotsGuard\Robots\RobotsFile;
use IndianOpinion\EchoRobotsGuard\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The Echo integration and its interplay with a duplicate check: T69 to T80.
 */
final class EchoIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configure(['mode' => 'enforce']);
        $this->plugin->boot();
        $this->robots('https://a.example', 'basic.txt');
    }

    private function echoFilter(mixed $value): mixed
    {
        return apply_filters(EchoIntegration::FILTER, $value);
    }

    public function testT69RegisteredAtPriority20AndConfigurable(): void
    {
        self::assertSame(20, has_filter(EchoIntegration::FILTER, [$this->plugin->echo, 'filter']));

        \WpStub::reset();
        add_filter('echo_robots_guard_priority', static fn (): int => 5);
        $plugin = $this->makePlugin();
        $plugin->boot();
        self::assertSame(5, has_filter(EchoIntegration::FILTER, [$plugin->echo, 'filter']));
    }

    public function testT70AlreadySkippedPassesThrough(): void
    {
        add_filter(EchoIntegration::FILTER, static fn (mixed $v): bool => true, 10);
        self::assertTrue($this->echoFilter('https://a.example/blocked'));
        self::assertTrue($this->echoFilter('https://a.example/fine'));
        self::assertSame([], \WpStub::$requests);
        $today = $this->today();
        self::assertSame(2, $today['passed_through']);
        self::assertArrayNotHasKey('checked', $today);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function notUrls(): array
    {
        return [
            'title' => ['Minister opens new bridge'],
            'integer' => [42],
            'array' => [['https://a.example/blocked']],
            'false' => [false],
            'null' => [null],
            'ftp' => ['ftp://a.example/blocked'],
        ];
    }

    #[DataProvider('notUrls')]
    public function testT71NonUrlValuesReturnedIdentical(mixed $value): void
    {
        self::assertSame($value, $this->echoFilter($value));
        self::assertSame([], \WpStub::$requests);
        self::assertArrayNotHasKey('checked', $this->today());
        self::assertArrayNotHasKey('not_checked', $this->today());
    }

    public function testT72EntityEncodedUrls(): void
    {
        self::assertTrue($this->echoFilter('https://a.example/x?a=1&amp;b=2'));
        $allowed = ' https://a.example/x?a=1&amp;c=3 ';
        self::assertSame($allowed, $this->echoFilter($allowed));
        $entries = $this->plugin->recorder->entries();
        self::assertSame('https://a.example/x?a=1&b=2', $entries[0]['url']);
    }

    public function testT73AllowedReturnsOriginal(): void
    {
        self::assertSame('https://a.example/fine', $this->echoFilter('https://a.example/fine'));
        self::assertSame(1, $this->today()['allowed']);
    }

    public function testT74DisallowedEnforceSkips(): void
    {
        self::assertTrue($this->echoFilter('https://a.example/blocked/1'));
        $today = $this->today();
        self::assertSame(1, $today['blocked']);
        self::assertArrayNotHasKey('would_block', $today);
        $entry = $this->plugin->recorder->entries()[0];
        self::assertSame('enforce', $entry['mode']);
        self::assertSame('echo', $entry['context']);
        self::assertSame('https://a.example', $entry['origin']);
        self::assertSame('User-agent: ExampleBot / Disallow: /blocked', $entry['rule']);
    }

    public function testT75DisallowedShadowRecordsOnly(): void
    {
        $this->configure(['mode' => 'shadow']);
        self::assertSame('https://a.example/blocked/1', $this->echoFilter('https://a.example/blocked/1'));
        $today = $this->today();
        self::assertSame(1, $today['would_block']);
        self::assertArrayNotHasKey('blocked', $today);
        self::assertSame('shadow', $this->plugin->recorder->entries()[0]['mode']);
    }

    public function testT76OffDoesNothingButApiStillAnswers(): void
    {
        $this->configure(['mode' => 'off']);
        self::assertSame('https://a.example/blocked', $this->echoFilter('https://a.example/blocked'));
        self::assertSame([], \WpStub::$requests);
        self::assertSame([], $this->today());
        self::assertNull($this->plugin->recorder->lastEchoCall());
        self::assertFalse($this->plugin->mayFetch('https://a.example/blocked')->allowed);
    }

    public function testT77InternalErrorLetsItemThrough(): void
    {
        \WpStub::reset();
        $parser = new class () extends Parser {
            public function parse(string $body): RobotsFile
            {
                throw new \LogicException('parser exploded');
            }
        };
        $plugin = $this->makePlugin($parser);
        Plugin::setInstance($plugin);
        $this->plugin = $plugin;
        $this->configure(['mode' => 'enforce']);
        $plugin->boot();
        $this->robots('https://a.example', 'basic.txt');

        self::assertSame('https://a.example/blocked', $this->echoFilter('https://a.example/blocked'));
        self::assertSame(1, $this->today()['internal_errors']);
        self::assertCount(1, $this->logLines);
        self::assertStringContainsString('parser exploded', $this->logLines[0]);
    }

    public function testT78LaterCallbackCanOverride(): void
    {
        add_filter(EchoIntegration::FILTER, static fn (mixed $v): bool => false, 30);
        self::assertFalse($this->echoFilter('https://a.example/blocked'));
        self::assertSame(1, $this->today()['blocked']);
    }

    public function testT79NoEchoCallsSeen(): void
    {
        ob_start();
        $this->plugin->admin->render();
        $html = (string) ob_get_clean();
        self::assertStringContainsString('No Echo calls seen yet', $html);

        $this->echoFilter('https://a.example/fine');
        ob_start();
        $this->plugin->admin->render();
        $html = (string) ob_get_clean();
        self::assertStringNotContainsString('No Echo calls seen yet', $html);
        self::assertStringContainsString('Last Echo call:', $html);
    }

    public function testT80SkipListPassesThrough(): void
    {
        $this->configure(['skip_hosts' => ['news.aggregator.example', '.redirect.example']]);
        foreach (['https://news.aggregator.example/x', 'https://go.redirect.example/y', 'https://redirect.example/z'] as $url) {
            self::assertSame($url, $this->echoFilter($url));
        }
        self::assertSame([], \WpStub::$requests);
        self::assertSame(3, $this->today()['not_checked']);
    }

    public function testNeverReturnsFalseOrChangesTrue(): void
    {
        foreach (['https://a.example/blocked', 'https://a.example/fine', true, 'title', false] as $value) {
            $result = $this->echoFilter($value);
            self::assertTrue($result === true || $result === $value);
        }
    }

    public function testBlockedActionFires(): void
    {
        $seen = [];
        add_action('echo_robots_guard_blocked', static function (string $url, mixed $rule, string $mode) use (&$seen): void {
            $seen[] = [$url, $rule, $mode];
        }, 10, 3);
        $this->echoFilter('https://a.example/blocked');
        self::assertSame([[
            'https://a.example/blocked',
            ['agent' => 'ExampleBot', 'directive' => 'disallow', 'pattern' => '/blocked'],
            'enforce',
        ]], $seen);
    }
}
