<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Tests\Integration;

use IndianOpinion\EchoRobotsGuard\Statement;
use IndianOpinion\EchoRobotsGuard\Tests\TestCase;

use function IndianOpinion\EchoRobotsGuard\statement;

/**
 * The /bot page statement: T93 to T97.
 */
final class StatementTest extends TestCase
{
    private const NOT_DOWNLOADED = 'are not downloaded';

    public function testT93EnforceClosed(): void
    {
        $this->configure(['mode' => 'enforce', 'fail_policy' => 'closed', 'contact_email' => 'hello@indianopinion.org']);
        $html = statement();
        self::assertStringContainsString('<code>ExampleBot</code>', $html);
        self::assertStringContainsString(self::NOT_DOWNLOADED, $html);
        self::assertStringContainsString('ExampleBot/0.1.0 (+https://site.example/bot)', $html);
        self::assertStringContainsString('RFC 9309', $html);
        self::assertStringContainsString('longest matching rule wins', $html);
        self::assertStringContainsString('Allow wins', $html);
        self::assertStringContainsString('404 or another 4xx', $html);
        self::assertStringContainsString('treated as disallowed', $html);
        self::assertStringContainsString('cached for 24 hours, or 60 minutes after an error', $html);
        self::assertStringContainsString('news feeds themselves are fetched without a robots.txt check', $html);
        self::assertStringContainsString('<code>User-agent: ExampleBot</code> <code>Disallow: /</code>', $html);
        self::assertStringContainsString('mailto:hello@indianopinion.org', $html);
    }

    public function testT94Shadow(): void
    {
        $this->configure(['mode' => 'shadow']);
        $html = statement();
        self::assertStringContainsString('recorded for review, but they are not yet skipped', $html);
        self::assertStringNotContainsString(self::NOT_DOWNLOADED, $html);
        self::assertStringNotContainsStringIgnoringCase('we honour', $html);
    }

    public function testT95Off(): void
    {
        $this->configure(['mode' => 'off']);
        $html = statement();
        self::assertStringContainsString('switched off', $html);
        self::assertStringNotContainsString(self::NOT_DOWNLOADED, $html);
    }

    public function testT96FailOpenAndSkipList(): void
    {
        $this->configure(['fail_policy' => 'open', 'skip_hosts' => ['news.aggregator.example']]);
        $html = statement();
        self::assertStringContainsString('treated as allowing access', $html);
        self::assertStringContainsString('does not follow RFC 9309', $html);
        self::assertStringContainsString('Links on some hosts are not checked', $html);
        self::assertStringNotContainsString('news.aggregator.example', $html);

        add_filter('echo_robots_guard_statement_name_skip_hosts', '__return_true_stub');
        self::assertStringContainsString('news.aggregator.example', statement());
    }

    public function testT97StatementFilter(): void
    {
        $this->configure(['mode' => 'shadow']);
        $seen = [];
        add_filter('echo_robots_guard_statement', static function (string $html, string $mode, array $settings) use (&$seen): string {
            $seen = [$mode, $settings['token'], $settings['fail_policy']];

            return '<p>Custom ' . $mode . '</p>';
        }, 10, 3);
        self::assertSame('<p>Custom shadow</p>', statement());
        self::assertSame(['shadow', 'ExampleBot', 'closed'], $seen);
    }

    public function testShortcode(): void
    {
        $this->configure([]);
        $this->plugin->boot();
        self::assertStringContainsString('echo-robots-guard-statement', do_shortcode('[' . Statement::SHORTCODE . ']'));
    }

    public function testTokenIsEscaped(): void
    {
        add_filter('echo_robots_guard_user_agent', static fn (): string => '<script>x</script>');
        $this->configure([]);
        self::assertStringNotContainsString('<script>', statement());
    }
}
