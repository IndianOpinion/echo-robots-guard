<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Tests\Integration;

use IndianOpinion\EchoRobotsGuard\Admin\AdminPage;
use IndianOpinion\EchoRobotsGuard\CacheEntry;
use IndianOpinion\EchoRobotsGuard\Cli\Command;
use IndianOpinion\EchoRobotsGuard\Store\TableStore;
use IndianOpinion\EchoRobotsGuard\Tests\Fixtures;
use IndianOpinion\EchoRobotsGuard\Tests\TestCase;

/**
 * Cache behaviour: T25 (integration half) and T60 to T68.
 */
final class CacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configure([]);
    }

    private function entry(string $origin): ?CacheEntry
    {
        $row = $GLOBALS['wpdb']->tables[TableStore::tableName()][CacheEntry::hash($origin)] ?? null;

        return is_array($row) ? CacheEntry::fromRow($row) : null;
    }

    public function testT25TwoTokensFromOneEntry(): void
    {
        $this->robots('https://a.example', 'star-and-named.txt');
        self::assertFalse($this->plugin->mayFetch('https://a.example/named', 'ExampleBot')->allowed);
        self::assertTrue($this->plugin->mayFetch('https://a.example/named', 'OtherBot')->allowed);
        self::assertFalse($this->plugin->mayFetch('https://a.example/star', 'OtherBot')->allowed);
        self::assertSame(1, $this->requestCount());
    }

    public function testT60EntryHoldsParsedGroupsNotTheBody(): void
    {
        $this->robots('https://a.example', 'comments.txt');
        $this->plugin->mayFetch('https://a.example/x');
        $row = $GLOBALS['wpdb']->tables[TableStore::tableName()][CacheEntry::hash('https://a.example')];
        self::assertSame('https://a.example', $row['origin']);
        self::assertSame('ok', $row['outcome']);
        self::assertSame(200, $row['status']);
        self::assertSame($this->clock->now(), $row['fetched_at']);
        self::assertSame(strlen(Fixtures::robots('comments.txt')), $row['bytes']);
        self::assertStringNotContainsString('keep out', (string) $row['body']);
        self::assertStringNotContainsString('#', (string) $row['body']);
        $entry = CacheEntry::fromRow($row);
        self::assertNotNull($entry?->rules);
        self::assertSame(['ExampleBot'], $entry->rules->groups[0]->agents);
    }

    public function testT61SuccessAndClientErrorTtlIs24Hours(): void
    {
        $this->robots('https://a.example', 'basic.txt');
        $this->robotsBody('https://b.example', '', 404);
        $this->plugin->mayFetch('https://a.example/x');
        $this->plugin->mayFetch('https://b.example/x');
        $now = $this->clock->now();
        self::assertSame($now + 86400, $this->entry('https://a.example')?->expiresAt);
        self::assertSame($now + 86400, $this->entry('https://b.example')?->expiresAt);
    }

    public function testT62TtlAboveADayAcceptedWithWarning(): void
    {
        $this->configure(['ttl' => 172800]);
        self::assertSame(172800, $this->plugin->settings->ttl());
        self::assertContains('ttl_over_24h', $this->plugin->settings->warnings());
        $texts = array_column($this->plugin->admin->notices(), 'text');
        self::assertNotEmpty(array_filter($texts, static fn (string $t): bool => str_contains($t, 'above 24 hours')));
        $this->plugin->mayFetch('https://a.example/x');
        self::assertSame($this->clock->now() + 172800, $this->entry('https://a.example')?->expiresAt);
    }

    public function testT63ErrorTtlOneHourAndClamped(): void
    {
        \WpStub::respond('https://a.example/robots.txt', 503);
        $this->plugin->mayFetch('https://a.example/x');
        self::assertSame($this->clock->now() + 3600, $this->entry('https://a.example')?->expiresAt);

        $this->configure(['ttl' => 600, 'error_ttl' => 7200]);
        self::assertSame(600, $this->plugin->settings->errorTtl());
    }

    public function testT64SecondCheckIsAHit(): void
    {
        $this->robots('https://a.example', 'basic.txt');
        self::assertSame('miss', $this->plugin->mayFetch('https://a.example/x')->cache);
        $this->plugin->guard->resetRequest();
        self::assertSame('hit', $this->plugin->mayFetch('https://a.example/y')->cache);
        self::assertSame(1, $this->requestCount());
        $today = $this->today('api');
        self::assertSame(1, $today['cache_hits']);
        self::assertSame(1, $today['cache_misses']);
    }

    public function testT65ExpiryRefetches(): void
    {
        $this->robots('https://a.example', 'basic.txt');
        $this->robotsBody('https://a.example', '');
        self::assertFalse($this->plugin->mayFetch('https://a.example/blocked')->allowed);
        $this->clock->advance(86401);
        $this->plugin->guard->resetRequest();
        $decision = $this->plugin->mayFetch('https://a.example/blocked');
        self::assertTrue($decision->allowed);
        self::assertSame('miss', $decision->cache);
        self::assertSame(2, $this->requestCount());
        self::assertSame($this->clock->now(), $this->entry('https://a.example')?->fetchedAt);
    }

    public function testT66ClearAllAndOneOriginFromAdminAndCli(): void
    {
        $this->robots('https://a.example', 'basic.txt');
        $this->robots('https://b.example', 'basic.txt');
        $this->plugin->mayFetch('https://a.example/x');
        $this->plugin->mayFetch('https://b.example/x');

        $_REQUEST['_wpnonce'] = 'nonce-' . AdminPage::CLEAR_ACTION;
        $this->plugin->admin->handleClear(['origin' => 'https://a.example']);
        self::assertNull($this->entry('https://a.example'));
        self::assertNotNull($this->entry('https://b.example'));

        $this->plugin->admin->handleClear([]);
        self::assertNull($this->entry('https://b.example'));

        $this->plugin->guard->resetRequest();
        $this->plugin->mayFetch('https://a.example/x');
        $this->plugin->mayFetch('https://b.example/x');
        self::assertSame(4, $this->requestCount());

        $cli = new Command(
            $this->plugin->settings,
            $this->plugin->guard,
            $this->plugin->cache,
            $this->plugin->recorder
        );
        $cli->cache(['clear', 'https://a.example'], []);
        self::assertNull($this->entry('https://a.example'));
        self::assertNotNull($this->entry('https://b.example'));
        $cli->cache(['clear'], []);
        self::assertNull($this->entry('https://b.example'));
        self::assertSame([], \WpStub::$cache['echo_robots_guard'] ?? []);
    }

    public function testT67SameAnswersWithAndWithoutObjectCache(): void
    {
        $answers = [];
        foreach ([true, false] as $objectCache) {
            \WpStub::reset();
            \WpStub::$objectCache = $objectCache;
            $this->plugin = $this->makePlugin();
            $this->configure([]);
            $this->robots('https://a.example', 'basic.txt');
            $run = [];
            for ($i = 0; $i < 2; $i++) {
                $this->plugin->guard->resetRequest();
                foreach (['/blocked', '/fine', '/x?a=1&b=2'] as $path) {
                    $run[] = $this->plugin->mayFetch('https://a.example' . $path)->allowed;
                }
            }
            self::assertSame(1, $this->requestCount());
            $answers[] = $run;
        }
        self::assertSame($answers[0], $answers[1]);
        self::assertSame([false, true, false, false, true, false], $answers[0]);
    }

    public function testT68SettingsChangesNeedNoCacheClear(): void
    {
        $this->robots('https://a.example', 'basic.txt');
        self::assertFalse($this->plugin->mayFetch('https://a.example/blocked')->allowed);
        $this->configure(['token' => 'OtherBot', 'mode' => 'shadow', 'fail_policy' => 'open']);
        $this->plugin->guard->resetRequest();
        $decision = $this->plugin->mayFetch('https://a.example/blocked');
        self::assertTrue($decision->allowed);
        self::assertSame('hit', $decision->cache);
        self::assertFalse($this->plugin->mayFetch('https://a.example/other')->allowed);
        self::assertSame(1, $this->requestCount());
    }

    public function testPurgeDropsOnlyDeadEntries(): void
    {
        $this->robots('https://a.example', 'basic.txt');
        $this->robotsBody('https://b.example', '', 404);
        $this->plugin->mayFetch('https://a.example/x');
        $this->plugin->mayFetch('https://b.example/x');
        $this->clock->advance(2 * 86400);
        $this->plugin->cache->purge(30 * 86400);
        self::assertNotNull($this->entry('https://a.example'));
        self::assertNull($this->entry('https://b.example'));
    }
}
