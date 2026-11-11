<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Tests\Integration;

use IndianOpinion\EchoRobotsGuard\Admin\AdminPage;
use IndianOpinion\EchoRobotsGuard\EchoIntegration;
use IndianOpinion\EchoRobotsGuard\Plugin;
use IndianOpinion\EchoRobotsGuard\Recorder;
use IndianOpinion\EchoRobotsGuard\Settings;
use IndianOpinion\EchoRobotsGuard\Store\TableStore;
use IndianOpinion\EchoRobotsGuard\Tests\TestCase;
use IndianOpinion\EchoRobotsGuard\Uninstaller;

/**
 * Multisite and must-use: T81 to T86.
 */
final class MultisiteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \WpStub::$multisite = true;
        \WpStub::$sites = [1, 2];
        $this->plugin->boot();
        $this->robots('https://a.example', 'star-and-named.txt');
    }

    private function onSite(int $blog, callable $fn): mixed
    {
        switch_to_blog($blog);
        try {
            return $fn();
        } finally {
            restore_current_blog();
        }
    }

    public function testT81PerSiteSettings(): void
    {
        $this->onSite(1, fn () => $this->configure(['mode' => 'enforce', 'token' => 'ExampleBot']));
        $this->onSite(2, fn () => $this->configure(['mode' => 'shadow', 'token' => 'OtherBot']));
        $one = $this->onSite(1, fn () => [$this->plugin->settings->mode(), $this->plugin->settings->token()]);
        $two = $this->onSite(2, fn () => [$this->plugin->settings->mode(), $this->plugin->settings->token()]);
        self::assertSame(['enforce', 'ExampleBot'], $one);
        self::assertSame(['shadow', 'OtherBot'], $two);
    }

    public function testT82SharedCacheTokensAppliedSeparately(): void
    {
        $this->onSite(1, fn () => $this->configure(['token' => 'ExampleBot']));
        $this->onSite(2, fn () => $this->configure(['token' => 'OtherBot']));
        $one = $this->onSite(1, fn () => $this->plugin->mayFetch('https://a.example/named')->allowed);
        $two = $this->onSite(2, fn () => $this->plugin->mayFetch('https://a.example/named')->allowed);
        $twoStar = $this->onSite(2, fn () => $this->plugin->mayFetch('https://a.example/star')->allowed);
        self::assertFalse($one);
        self::assertTrue($two);
        self::assertFalse($twoStar);
        self::assertSame(1, $this->requestCount());
        self::assertSame('wp_echo_robots_guard_cache', TableStore::tableName());
        self::assertContains('echo_robots_guard', \WpStub::$globalGroups);
    }

    public function testT83CountersAndLogPerSite(): void
    {
        $this->onSite(1, fn () => $this->configure(['mode' => 'enforce']));
        $this->onSite(2, fn () => $this->configure(['mode' => 'enforce']));
        $this->onSite(1, fn () => apply_filters(EchoIntegration::FILTER, 'https://a.example/named'));
        $this->plugin->recorder->flush();

        $one = $this->onSite(1, fn () => [get_option(Recorder::COUNTERS), get_option(Recorder::LOG)]);
        $two = $this->onSite(2, fn () => [get_option(Recorder::COUNTERS, null), get_option(Recorder::LOG, null)]);
        self::assertSame(1, $one[0]['total']['echo']['blocked']);
        self::assertCount(1, $one[1]);
        self::assertSame([null, null], $two);
        self::assertFalse(\WpStub::$autoload[1][Recorder::COUNTERS]);
        self::assertFalse(\WpStub::$autoload[1][Recorder::LOG]);
    }

    public function testT83FlushWritesEachSiteFromAnotherSite(): void
    {
        $this->onSite(2, fn () => $this->plugin->recorder->count('echo', 'blocked'));
        $this->plugin->recorder->count('echo', 'allowed');
        $this->plugin->recorder->flush();
        self::assertSame(1, \WpStub::$options[2][Recorder::COUNTERS]['total']['echo']['blocked']);
        self::assertSame(1, \WpStub::$options[1][Recorder::COUNTERS]['total']['echo']['allowed']);
        self::assertSame(1, get_current_blog_id());
    }

    public function testT84NetworkActivationAndLaterSiteGetDefaults(): void
    {
        Plugin::activate();
        self::assertSame(TableStore::DB_VERSION, get_site_option(TableStore::VERSION_OPTION));
        self::assertArrayHasKey(TableStore::tableName(), $GLOBALS['wpdb']->tables);

        \WpStub::$sites = [1, 2, 3];
        $settings = $this->onSite(3, fn () => [
            $this->plugin->settings->mode(),
            $this->plugin->settings->token(),
            get_option(Settings::OPTION),
        ]);
        self::assertSame('enforce', $settings[0]);
        self::assertSame('DailyExampleBot', $settings[1]);
        self::assertIsArray($settings[2]);
        self::assertTrue(\WpStub::$autoload[3][Settings::OPTION]);
    }

    /**
     * Run in a separate process so the constants do not leak.
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testT85MustUseConstantsWinAndAreReadOnly(): void
    {
        define('ECHO_ROBOTS_GUARD_MODE', 'shadow');
        define('ECHO_ROBOTS_GUARD_TOKEN', 'ConstBot');
        $this->configure(['mode' => 'enforce', 'token' => 'ExampleBot']);
        self::assertSame('shadow', $this->plugin->settings->mode());
        self::assertSame('ConstBot', $this->plugin->settings->token());

        $_REQUEST['_wpnonce'] = 'nonce-' . AdminPage::SAVE_ACTION;
        try {
            $this->plugin->admin->handleSave(['echo_robots_guard' => ['mode' => 'off', 'token' => 'Changed']]);
        } catch (\WpRedirectException) {
        }
        self::assertSame('shadow', $this->plugin->settings->mode());
        self::assertSame('ConstBot', $this->plugin->settings->token());

        ob_start();
        $this->plugin->admin->render();
        $html = (string) ob_get_clean();
        self::assertStringContainsString('Fixed in code by ECHO_ROBOTS_GUARD_MODE (read-only)', $html);
        self::assertStringContainsString('Fixed in code by ECHO_ROBOTS_GUARD_TOKEN (read-only)', $html);
        self::assertMatchesRegularExpression('/name="echo_robots_guard\[mode\]" disabled/', $html);
        self::assertMatchesRegularExpression('/name="echo_robots_guard\[token\]" value="ConstBot" readonly/', $html);
    }

    public function testT86UninstallRemovesEverythingOnEverySite(): void
    {
        foreach ([1, 2] as $blog) {
            $this->onSite($blog, function (): void {
                $this->configure(['mode' => 'enforce']);
                apply_filters(EchoIntegration::FILTER, 'https://a.example/star');
                $this->plugin->guard->resetRequest();
            });
        }
        $this->plugin->recorder->flush();
        \WpStub::$scheduled[Plugin::PURGE_EVENT] = 1;
        self::assertNotEmpty($GLOBALS['wpdb']->tables[TableStore::tableName()]);

        Uninstaller::run();

        foreach ([1, 2] as $blog) {
            foreach ([Settings::OPTION, Recorder::COUNTERS, Recorder::LOG] as $option) {
                self::assertArrayNotHasKey($option, \WpStub::$options[$blog] ?? [], "site {$blog} {$option}");
            }
        }
        self::assertArrayNotHasKey(TableStore::tableName(), $GLOBALS['wpdb']->tables);
        self::assertArrayNotHasKey(TableStore::VERSION_OPTION, \WpStub::$siteOptions);
        self::assertArrayNotHasKey('echo_robots_guard', \WpStub::$cache);
        self::assertArrayNotHasKey(Plugin::PURGE_EVENT, \WpStub::$scheduled);
    }

    public function testPurgeScheduledFromTheMainSiteOnly(): void
    {
        unset(\WpStub::$scheduled[Plugin::PURGE_EVENT]);
        $this->onSite(2, function (): void {
            $this->plugin->schedulePurge();
        });
        self::assertArrayNotHasKey(Plugin::PURGE_EVENT, \WpStub::$scheduled);
        $this->plugin->schedulePurge();
        self::assertArrayHasKey(Plugin::PURGE_EVENT, \WpStub::$scheduled);
    }
}
