<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Tests\Integration;

use IndianOpinion\EchoRobotsGuard\EchoIntegration;
use IndianOpinion\EchoRobotsGuard\Plugin;
use IndianOpinion\EchoRobotsGuard\Statement;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Checks on the source tree and the README: T91, T98, T108; and that the
 * plugin file loads and registers its hooks.
 */
final class SourceTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /**
     * @return list<string>
     */
    private static function phpFiles(): array
    {
        $files = [self::ROOT . '/echo-robots-guard.php', self::ROOT . '/uninstall.php', self::ROOT . '/mu-loader/echo-robots-guard-loader.php'];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/src'));
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    public function testT108NoEvalNoShellNoSuppression(): void
    {
        $banned = [T_EVAL => 'eval'];
        $functions = ['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'assert', 'create_function'];
        foreach (self::phpFiles() as $file) {
            $tokens = token_get_all((string) file_get_contents($file));
            foreach ($tokens as $i => $token) {
                if ($token === '@' || $token === '`') {
                    self::fail("{$file}: {$token} found");
                }
                if (!is_array($token)) {
                    continue;
                }
                if (isset($banned[$token[0]])) {
                    self::fail("{$file}:{$token[2]}: {$banned[$token[0]]}");
                }
                if ($token[0] === T_STRING && in_array(strtolower($token[1]), $functions, true)) {
                    $next = $tokens[$i + 1] ?? null;
                    if ($next === '(') {
                        self::fail("{$file}:{$token[2]}: {$token[1]}()");
                    }
                }
            }
        }
        self::assertGreaterThan(10, count(self::phpFiles()));
    }

    public function testEveryLibraryFileDeclaresStrictTypes(): void
    {
        foreach (self::phpFiles() as $file) {
            self::assertStringContainsString('declare(strict_types=1);', (string) file_get_contents($file), $file);
        }
    }

    public function testT91ReadmeStatesFeedLimitAndPolicy(): void
    {
        $readme = (string) file_get_contents(self::ROOT . '/README.md');
        self::assertStringContainsString('feed URL is never checked', $readme);
        self::assertStringContainsString('wp echo-robots-guard check', $readme);
        self::assertStringContainsString('check -', $readme);
        self::assertStringContainsString('email address', $readme);
    }

    public function testT98ReadmeWarnsAboutBotPageGuess(): void
    {
        $readme = (string) file_get_contents(self::ROOT . '/README.md');
        self::assertStringContainsString('301', $readme);
        self::assertMatchesRegularExpression('/slug starts with\s+"bot"/', $readme);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPluginFileLoadsAndRegistersHooks(): void
    {
        require self::ROOT . '/echo-robots-guard.php';
        $plugin = Plugin::instance();
        self::assertSame(20, has_filter(EchoIntegration::FILTER, [$plugin->echo, 'filter']));
        self::assertNotFalse(has_filter('echo_robots_guard_may_fetch'));
        self::assertNotFalse(has_filter('shutdown'));
        self::assertArrayHasKey(Statement::SHORTCODE, \WpStub::$shortcodes);
        self::assertNotFalse(has_filter('activate_' . plugin_basename(ECHO_ROBOTS_GUARD_FILE)));

        do_action('activate_' . plugin_basename(ECHO_ROBOTS_GUARD_FILE));
        self::assertArrayHasKey($GLOBALS['wpdb']->base_prefix . 'echo_robots_guard_cache', $GLOBALS['wpdb']->tables);

        do_action('init');
        self::assertArrayHasKey(Plugin::PURGE_EVENT, \WpStub::$scheduled);

        // Loading twice (plugin and must-use) is harmless.
        require self::ROOT . '/echo-robots-guard.php';
        self::assertSame($plugin, Plugin::instance());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMustUseLoader(): void
    {
        $dir = sys_get_temp_dir() . '/erg-mu-' . getmypid();
        mkdir($dir);
        symlink(realpath(self::ROOT), $dir . '/echo-robots-guard');
        define('WPMU_PLUGIN_DIR', $dir);
        try {
            require self::ROOT . '/mu-loader/echo-robots-guard-loader.php';
            self::assertTrue(defined('ECHO_ROBOTS_GUARD_FILE'));
            self::assertTrue(Plugin::isLoaded());
            // No activation hook runs: the table appears on first use.
            \WpStub::respond('https://a.example/robots.txt', 200, '');
            Plugin::instance()->mayFetch('https://a.example/x');
            self::assertArrayHasKey('wp_echo_robots_guard_cache', $GLOBALS['wpdb']->tables);
        } finally {
            unlink($dir . '/echo-robots-guard');
            rmdir($dir);
        }
    }
}
