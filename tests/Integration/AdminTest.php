<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Tests\Integration;

use IndianOpinion\EchoRobotsGuard\Admin\AdminPage;
use IndianOpinion\EchoRobotsGuard\EchoIntegration;
use IndianOpinion\EchoRobotsGuard\Settings;
use IndianOpinion\EchoRobotsGuard\Tests\TestCase;

/**
 * Admin page: T103, T104 and the settings defaults (decisions 2 and 3).
 */
final class AdminTest extends TestCase
{
    private function render(): string
    {
        ob_start();
        try {
            $this->plugin->admin->render();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    public function testT103AdminSeesEverythingEditorRefused(): void
    {
        $this->configure(['mode' => 'shadow']);
        $this->plugin->boot();
        $this->robots('https://a.example', 'basic.txt');
        apply_filters(EchoIntegration::FILTER, 'https://a.example/blocked?x=<b>');

        $html = $this->render();
        self::assertStringContainsString('Counters', $html);
        self::assertStringContainsString('would_block', $html);
        self::assertStringContainsString('https://a.example/blocked?x=&lt;b&gt;', $html);
        self::assertStringNotContainsString('?x=<b>', $html);
        self::assertStringContainsString('User-agent: ExampleBot / Disallow: /blocked', $html);
        self::assertStringContainsString('<code>https://a.example</code>', $html);
        self::assertStringContainsString('name="_wpnonce" value="nonce-' . AdminPage::SAVE_ACTION . '"', $html);
        self::assertStringContainsString('name="_wpnonce" value="nonce-' . AdminPage::CLEAR_ACTION . '"', $html);

        \WpStub::$caps = ['edit_posts'];
        $this->expectException(\WpDieException::class);
        $this->render();
    }

    public function testAdminMenuUnderTools(): void
    {
        \WpStub::$admin = true;
        $this->plugin->boot();
        do_action('admin_menu');
        self::assertSame([[AdminPage::SLUG, 'manage_options']], \WpStub::$menuPages);
    }

    public function testT104SaveWithoutNonceRefused(): void
    {
        $this->configure(['mode' => 'enforce']);
        try {
            $this->plugin->admin->handleSave(['echo_robots_guard' => ['mode' => 'off']]);
            self::fail('Saved without a nonce');
        } catch (\WpDieException) {
        }
        self::assertSame('enforce', $this->plugin->settings->mode());
    }

    public function testT104SaveWithoutCapabilityRefused(): void
    {
        $this->configure(['mode' => 'enforce']);
        $_REQUEST['_wpnonce'] = 'nonce-' . AdminPage::SAVE_ACTION;
        \WpStub::$caps = ['edit_posts'];
        try {
            $this->plugin->admin->handleSave(['echo_robots_guard' => ['mode' => 'off']]);
            self::fail('Saved without the capability');
        } catch (\WpDieException) {
        }
        try {
            $this->plugin->admin->handleClear([]);
            self::fail('Cleared without a nonce');
        } catch (\WpDieException) {
        }
        self::assertSame('enforce', $this->plugin->settings->mode());
    }

    public function testT104BadValuesRejectedWithNotice(): void
    {
        $_REQUEST['_wpnonce'] = 'nonce-' . AdminPage::SAVE_ACTION;
        $url = $this->plugin->admin->handleSave(['echo_robots_guard' => [
            'mode' => 'shadow',
            'token' => 'Bad Token!',
            'ttl' => '10',
            'timeout' => '5',
            'bot_page_url' => 'javascript:alert(1)',
            'skip_hosts' => "news.aggregator.example\n.redirect.example",
        ]]);
        self::assertStringContainsString('rejected=token%2Cttl%2Cbot_page_url', $url);
        $s = $this->plugin->settings;
        self::assertSame('shadow', $s->mode());
        self::assertSame('DailyExampleBot', $s->token());
        self::assertSame(86400, $s->ttl());
        self::assertSame(5, $s->timeout());
        self::assertSame(['news.aggregator.example', '.redirect.example'], $s->skipHosts());
        self::assertTrue($s->isConfirmed());
    }

    public function testT104InvalidStoredValueFallsBackWithNotice(): void
    {
        $this->configure(['fail_policy' => 'sideways', 'timeout' => 99]);
        self::assertSame('closed', $this->plugin->settings->failPolicy());
        self::assertSame(8, $this->plugin->settings->timeout());
        self::assertSame(['fail_policy', 'timeout'], $this->plugin->settings->rejected());
        $texts = implode("\n", array_column($this->plugin->admin->notices(), 'text'));
        self::assertStringContainsString('"fail_policy" is not valid', $texts);
        self::assertStringContainsString('"timeout" is not valid', $texts);
    }

    public function testDefaultsOnFreshInstall(): void
    {
        $s = $this->plugin->settings;
        self::assertSame('enforce', $s->mode());
        self::assertSame('DailyExampleBot', $s->token());
        self::assertSame('closed', $s->failPolicy());
        self::assertSame(86400, $s->ttl());
        self::assertSame(3600, $s->errorTtl());
        self::assertSame(2592000, $s->staleWindow());
        self::assertSame(8, $s->timeout());
        self::assertSame(20, $s->maxFetches());
        self::assertSame(512000, $s->maxBytes());
        self::assertSame(10000, $s->maxRules());
        self::assertSame('https://site.example/bot', $s->botPageUrl());
        self::assertSame([], $s->skipHosts());
        self::assertFalse($s->isConfirmed());

        $texts = implode("\n", array_column($this->plugin->admin->notices(), 'text'));
        self::assertStringContainsString('Please confirm the product token DailyExampleBot', $texts);
        self::assertStringContainsString('shadow mode', $texts);
    }

    public function testDerivedTokenIsStableAfterTitleChange(): void
    {
        self::assertSame('DailyExampleBot', $this->plugin->settings->token());
        \WpStub::$blogname = 'Something Else';
        self::assertSame('DailyExampleBot', $this->plugin->settings->token());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function titles(): array
    {
        return [
            'two words' => ['Daily Example', 'DailyExampleBot'],
            'ends in bot' => ['Robo News Bot', 'RoboNewsBot'],
            'punctuation and digits' => ['The 24/7 Times!', 'TheTimesBot'],
            'empty' => ['', Settings::DEFAULT_TOKEN],
            'no letters' => ['123 456', Settings::DEFAULT_TOKEN],
            'long' => [str_repeat('Word ', 30), str_repeat('Word', 15) . 'W' . 'Bot'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('titles')]
    public function testTokenFromTitle(string $title, string $expected): void
    {
        $token = Settings::tokenFromTitle($title);
        self::assertSame($expected, $token);
        self::assertTrue(Settings::isValidToken($token));
    }

    public function testMaxBytesNeverBelowRfcMinimum(): void
    {
        add_filter('echo_robots_guard_max_bytes', static fn (): int => 1000);
        self::assertSame(512000, $this->plugin->settings->maxBytes());
    }

    public function testGlobalNoticeUntilConfirmed(): void
    {
        ob_start();
        $this->plugin->admin->globalNotice();
        self::assertStringContainsString('Confirm or change the token', (string) ob_get_clean());
        $this->configure([]);
        ob_start();
        $this->plugin->admin->globalNotice();
        self::assertSame('', ob_get_clean());
    }

    public function testNoEchoCallsInSevenDaysNotice(): void
    {
        $this->configure([]);
        $this->plugin->boot();
        apply_filters(EchoIntegration::FILTER, 'title only');
        $this->plugin->recorder->flush();
        $this->clock->advance(8 * 86400);
        $texts = implode("\n", array_column($this->plugin->admin->notices(), 'text'));
        self::assertStringContainsString('No Echo calls seen in the last 7 days', $texts);
    }

    public function testT104RejectedValuesShownAfterRedirect(): void
    {
        $notices = $this->plugin->admin->resultNotices(['updated' => '1', 'rejected' => 'token,ttl,<script>']);
        self::assertSame(['success', 'error', 'error'], array_column($notices, 'type'));
        $texts = implode("\n", array_column($notices, 'text'));
        self::assertStringContainsString('"token" was not valid', $texts);
        self::assertStringContainsString('"ttl" was not valid', $texts);
        self::assertStringNotContainsString('script', $texts);
        self::assertSame('success', $this->plugin->admin->resultNotices(['cleared' => '1'])[0]['type']);
        self::assertSame([], $this->plugin->admin->resultNotices([]));
    }
}
