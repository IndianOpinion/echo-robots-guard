<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Tests;

use IndianOpinion\EchoRobotsGuard\Logger;
use IndianOpinion\EchoRobotsGuard\Plugin;
use IndianOpinion\EchoRobotsGuard\Robots\Parser;
use IndianOpinion\EchoRobotsGuard\Settings;
use IndianOpinion\EchoRobotsGuard\Store\TableStore;

/**
 * Base for tests that need the WordPress stub layer and a wired plugin.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected FixedClock $clock;

    protected Plugin $plugin;

    /** @var list<string> */
    protected array $logLines = [];

    protected function setUp(): void
    {
        parent::setUp();
        \WpStub::reset();
        $this->clock = new FixedClock();
        $this->logLines = [];
        $this->plugin = $this->makePlugin();
        Plugin::setInstance($this->plugin);
    }

    protected function tearDown(): void
    {
        Plugin::setInstance(null);
        parent::tearDown();
    }

    protected function makePlugin(?Parser $parser = null): Plugin
    {
        $logger = new Logger(function (string $line): bool {
            $this->logLines[] = $line;

            return true;
        });

        return new Plugin($this->clock, new TableStore(), $logger, null, $parser);
    }

    /**
     * Saves settings directly, marking them confirmed.
     *
     * @param array<string, mixed> $values
     */
    protected function configure(array $values): void
    {
        $stored = $this->plugin->settings->stored();
        update_option(Settings::OPTION, array_merge($stored, ['token' => 'ExampleBot', 'confirmed' => true], $values));
    }

    protected function robots(string $origin, string $fixture, int $status = 200): void
    {
        \WpStub::respond($origin . '/robots.txt', $status, Fixtures::robots($fixture));
    }

    protected function robotsBody(string $origin, string $body, int $status = 200): void
    {
        \WpStub::respond($origin . '/robots.txt', $status, $body);
    }

    protected function requestCount(): int
    {
        return count(\WpStub::$requests);
    }

    /**
     * @return array<string, int>
     */
    protected function today(string $context = 'echo'): array
    {
        return $this->plugin->recorder->summary($context)['today'];
    }
}
