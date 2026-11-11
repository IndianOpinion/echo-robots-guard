<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Tests\Unit;

use IndianOpinion\EchoRobotsGuard\Robots\Matcher;
use IndianOpinion\EchoRobotsGuard\Robots\Normalizer;
use IndianOpinion\EchoRobotsGuard\Robots\Parser;
use IndianOpinion\EchoRobotsGuard\Robots\RobotsFile;
use IndianOpinion\EchoRobotsGuard\Robots\Target;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use IndianOpinion\EchoRobotsGuard\Tests\Budget;
use PHPUnit\Framework\TestCase;

/**
 * Matching: T26 to T41 (PRD section 12). No WordPress.
 */
#[CoversClass(Matcher::class)]
#[CoversClass(Normalizer::class)]
#[CoversClass(Target::class)]
final class MatcherTest extends TestCase
{
    private static function rules(string $rules): RobotsFile
    {
        return (new Parser())->parse("User-agent: ExampleBot\n" . $rules . "\n");
    }

    private static function allowedUrl(RobotsFile $file, string $url): bool
    {
        $target = Target::fromUrl($url);
        self::assertNotNull($target);

        return Matcher::decide($file, 'ExampleBot', $target->path)->allowed;
    }

    private static function allowed(RobotsFile $file, string $path): bool
    {
        return self::allowedUrl($file, 'https://a.example' . $path);
    }

    public function testT26PrefixMatchAndQuery(): void
    {
        $file = self::rules('Disallow: /a');
        self::assertFalse(self::allowed($file, '/a'));
        self::assertFalse(self::allowed($file, '/abc'));
        self::assertFalse(self::allowed($file, '/a/b'));
        self::assertTrue(self::allowed($file, '/b'));
        self::assertSame('/?x', Target::fromUrl('https://a.example?x')?->path);
        self::assertSame('/?x', Target::fromUrl('https://a.example/?x')?->path);
    }

    public function testT27FragmentDroppedEmptyPathIsSlash(): void
    {
        self::assertSame('/', Target::fromUrl('https://a.example')?->path);
        self::assertSame('/p?q=1', Target::fromUrl('https://a.example/p?q=1#frag')?->path);
        $file = self::rules('Disallow: /p$');
        self::assertFalse(self::allowedUrl($file, 'https://a.example/p#section'));
    }

    public function testT28LongestMatchWins(): void
    {
        $file = self::rules("Allow: /p\nDisallow: /p/x");
        self::assertFalse(self::allowed($file, '/p/x/1'));
        self::assertTrue(self::allowed($file, '/p/y'));
    }

    public function testT29LongerAllowBeatsShorterDisallow(): void
    {
        $file = self::rules("Disallow: /folder/\nAllow: /folder/page");
        self::assertTrue(self::allowed($file, '/folder/page'));
        self::assertFalse(self::allowed($file, '/folder/other'));
    }

    public function testT30TieGoesToAllow(): void
    {
        // Both patterns are five octets long and both match "/page".
        $file = self::rules("Disallow: /*age\nAllow: /page");
        self::assertTrue(self::allowed($file, '/page'));
        $file = self::rules("Allow: /page\nDisallow: /*age");
        self::assertTrue(self::allowed($file, '/page'));
        // "/*page" is one octet longer than "/page", so it wins (M3).
        $file = self::rules("Allow: /page\nDisallow: /*page");
        self::assertFalse(self::allowed($file, '/page'));
    }

    public function testT31NoMatchAllows(): void
    {
        $file = self::rules("Disallow: /x\nDisallow: /y");
        $result = Matcher::decide($file, 'ExampleBot', '/z');
        self::assertTrue($result->allowed);
        self::assertNull($result->rule);
    }

    public function testT32Wildcards(): void
    {
        $file = self::rules("Disallow: /*.php\nDisallow: /a**b");
        self::assertFalse(self::allowed($file, '/x/y.php'));
        self::assertFalse(self::allowed($file, '/a/zzz/b'));
        self::assertTrue(self::allowed($file, '/a/zzz/c'));
        self::assertSame('/a*b', $file->groups[0]->rules[1]->pattern);
    }

    public function testT33EndAnchor(): void
    {
        $file = self::rules("Disallow: /*.php$\nDisallow: /a\$b");
        self::assertFalse(self::allowed($file, '/x.php'));
        self::assertTrue(self::allowed($file, '/x.php?y'));
        self::assertTrue(self::allowed($file, '/x.phpx'));
        self::assertFalse(self::allowed($file, '/a$b'));
        self::assertFalse(self::allowed($file, '/a$bc'));
        self::assertTrue(self::allowed($file, '/ab'));
    }

    public function testT34DisallowAllWithAllow(): void
    {
        $file = self::rules("Disallow: /\nAllow: /public");
        self::assertFalse(self::allowed($file, '/'));
        self::assertFalse(self::allowedUrl($file, 'https://a.example'));
        self::assertTrue(self::allowed($file, '/public/1'));
    }

    public function testT35RobotsTxtAlwaysAllowed(): void
    {
        $file = self::rules('Disallow: /');
        self::assertTrue(self::allowed($file, '/robots.txt'));
        self::assertFalse(self::allowed($file, '/robots.txt.bak'));
    }

    public function testT36PercentEncodingNormalised(): void
    {
        $file = self::rules("Disallow: /caf%C3%A9\nDisallow: /%7Euser");
        self::assertFalse(self::allowed($file, '/café'));
        self::assertFalse(self::allowed($file, '/caf%c3%a9'));
        self::assertFalse(self::allowed($file, '/caf%C3%A9/more'));
        self::assertFalse(self::allowed($file, '/~user/x'));
        $file = self::rules('Disallow: /café');
        self::assertFalse(self::allowed($file, '/caf%C3%A9'));
    }

    public function testT37ReservedCharactersStayEncoded(): void
    {
        $file = self::rules('Disallow: /a/b');
        self::assertTrue(self::allowed($file, '/a%2Fb'));
        self::assertTrue(self::allowed($file, '/a%2fb'));
    }

    public function testT38LiteralStarAndDollar(): void
    {
        $file = self::rules("Disallow: /x%2A\nDisallow: /price%24");
        self::assertFalse(self::allowed($file, '/x*'));
        self::assertFalse(self::allowed($file, '/x%2a'));
        self::assertTrue(self::allowed($file, '/x'));
        self::assertTrue(self::allowed($file, '/xyz'));
        self::assertFalse(self::allowed($file, '/price$'));
        self::assertTrue(self::allowed($file, '/price'));
    }

    public function testT39QueryMatters(): void
    {
        $file = self::rules('Disallow: /*?utm_');
        self::assertFalse(self::allowed($file, '/story?utm_source=x'));
        self::assertTrue(self::allowed($file, '/story'));
    }

    public function testT40PathsAreCaseSensitive(): void
    {
        $file = self::rules('Disallow: /Private');
        self::assertTrue(self::allowed($file, '/private'));
        self::assertFalse(self::allowed($file, '/Private'));
    }

    public function testT41LinearTimeOnHostilePatterns(): void
    {
        // Distinct patterns, so the de-duplication of identical rules does
        // not hide the cost of matching each one.
        $rules = '';
        for ($i = 0; $i < 10000; $i++) {
            $rules .= 'Disallow: /*a*a*a*a*b' . $i . "\n";
        }
        $file = self::rules($rules);
        self::assertCount(10000, $file->groups[0]->rules);
        $path = '/' . str_repeat('a', 1999);

        $best = PHP_FLOAT_MAX;
        for ($i = 0; $i < 3; $i++) {
            $start = hrtime(true);
            $result = Matcher::decide($file, 'ExampleBot', $path);
            $best = min($best, (hrtime(true) - $start) / 1e6);
            self::assertTrue($result->allowed);
        }
        self::assertLessThan(20.0 * Budget::factor(), $best, 'Match of 10,000 rules over 2,000 bytes took ' . $best . ' ms');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function patternCases(): array
    {
        return [
            'exact anchored' => ['/a$', '/a', true],
            'anchored longer' => ['/a$', '/ab', false],
            'star then anchor' => ['/*b$', '/ab', true],
            'star anchor overlap' => ['/a*a$', '/a', false],
            'star anchor two' => ['/a*a$', '/aa', true],
            'leading star' => ['*.pdf', '/x.pdf', true],
            'empty tail star' => ['/a*', '/a', true],
            'middle pieces in order' => ['/a*b*c', '/xacb', false],
            'middle pieces' => ['/a*b*c', '/a-b-c-d', true],
            'only star' => ['*', '/', true],
            'only star anchor' => ['*$', '/anything', true],
        ];
    }

    #[DataProvider('patternCases')]
    public function testPatternMatching(string $pattern, string $path, bool $expected): void
    {
        self::assertSame($expected, Matcher::matches(Normalizer::pattern($pattern), Normalizer::path($path)));
    }

    public function testIdenticalRulesCollapse(): void
    {
        $file = self::rules(str_repeat("Disallow: /*a*a*a*a*b\n", 10000));
        self::assertCount(1, $file->selection('ExampleBot')['rules']);
        self::assertTrue(self::allowed($file, '/' . str_repeat('a', 1999)));
        self::assertFalse(self::allowed($file, '/aaaab'));
    }

    public function testNormaliserRules(): void
    {
        self::assertSame('/~a/A%2F%C3%A9%20%25', Normalizer::path('/%7ea/%41%2f%c3%a9 %'));
        self::assertSame('/a%24b$', Normalizer::pattern('/a$b$'));
        self::assertSame('/a*b', Normalizer::pattern('/a***b'));
    }
}
