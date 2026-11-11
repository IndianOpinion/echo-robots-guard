<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard\Tests\Unit;

use IndianOpinion\EchoRobotsGuard\Robots\Matcher;
use IndianOpinion\EchoRobotsGuard\Robots\Normalizer;
use IndianOpinion\EchoRobotsGuard\Robots\Parser;
use IndianOpinion\EchoRobotsGuard\Robots\RobotsFile;
use IndianOpinion\EchoRobotsGuard\Tests\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Parsing and group selection: T01 to T25 (PRD section 12). No WordPress.
 */
#[CoversClass(Parser::class)]
#[CoversClass(Matcher::class)]
final class ParserTest extends TestCase
{
    private function parse(string $fixture): RobotsFile
    {
        return (new Parser())->parse(Fixtures::robots($fixture));
    }

    private static function allowed(RobotsFile $file, string $path, string $token = 'ExampleBot'): bool
    {
        return Matcher::decide($file, $token, Normalizer::path($path))->allowed;
    }

    /**
     * @return list<string>
     */
    private static function patterns(RobotsFile $file, int $group): array
    {
        return array_map(static fn ($r): string => $r->pattern, $file->groups[$group]->rules);
    }

    public function testT01MixedLineEndings(): void
    {
        $file = $this->parse('line-endings-mixed.txt');
        self::assertCount(3, $file->groups);
        self::assertSame(['/lf'], self::patterns($file, 0));
        self::assertSame(['/cr'], self::patterns($file, 1));
        self::assertSame(['/crlf'], self::patterns($file, 2));
    }

    public function testT02ByteOrderMarkIgnored(): void
    {
        $file = $this->parse('bom.txt');
        self::assertCount(1, $file->groups);
        self::assertSame(['ExampleBot'], $file->groups[0]->agents);
        self::assertFalse(self::allowed($file, '/bom'));
    }

    public function testT03CommentsNeverBecomeRules(): void
    {
        $file = $this->parse('comments.txt');
        self::assertSame(['ExampleBot'], $file->groups[0]->agents);
        self::assertSame(['/private', '/private/ok'], self::patterns($file, 0));
        self::assertTrue(self::allowed($file, '/commented'));
        self::assertTrue(self::allowed($file, '/also-commented'));
    }

    public function testT04WhitespaceAroundKeysColonsAndValues(): void
    {
        $file = $this->parse('whitespace.txt');
        self::assertSame(['/spaced', '/spaced/ok'], self::patterns($file, 0));
        self::assertFalse(self::allowed($file, '/spaced/x'));
        self::assertTrue(self::allowed($file, '/spaced/ok'));
    }

    public function testT05KeysAreCaseInsensitive(): void
    {
        $file = $this->parse('key-case.txt');
        self::assertFalse(self::allowed($file, '/upper/x'));
        self::assertTrue(self::allowed($file, '/upper/ok'));
    }

    public function testT06LinesWithoutColonIgnored(): void
    {
        $file = $this->parse('no-colon.txt');
        self::assertCount(1, $file->groups);
        self::assertSame(['/real'], self::patterns($file, 0));
    }

    public function testT07UnknownKeysDoNotSplitGroups(): void
    {
        $file = $this->parse('unknown-keys.txt');
        self::assertCount(1, $file->groups);
        self::assertSame(['ExampleBot', 'OtherBot'], $file->groups[0]->agents);
        self::assertSame(['/shared', '/shared2'], self::patterns($file, 0));
    }

    public function testT08ConsecutiveAgentsShareRules(): void
    {
        $file = $this->parse('grouping.txt');
        self::assertCount(2, $file->groups);
        self::assertFalse(self::allowed($file, '/both', 'ExampleBot'));
        self::assertFalse(self::allowed($file, '/both', 'OtherBot'));
        self::assertTrue(self::allowed($file, '/third', 'ExampleBot'));
        self::assertFalse(self::allowed($file, '/third', 'ThirdBot'));
        self::assertTrue(self::allowed($file, '/both', 'ThirdBot'));
    }

    public function testT09RulesBeforeFirstAgentIgnored(): void
    {
        $file = $this->parse('rules-before-agent.txt');
        self::assertSame(['/late'], self::patterns($file, 0));
        self::assertTrue(self::allowed($file, '/early'));
    }

    public function testT10GroupWithNoRulesAllowsAndBeatsStar(): void
    {
        $file = $this->parse('empty-group.txt');
        self::assertTrue(self::allowed($file, '/x'));
        self::assertFalse(self::allowed($file, '/x', 'OtherBot'));
    }

    public function testT11EmptyDisallowAllows(): void
    {
        $file = $this->parse('empty-disallow.txt');
        self::assertSame([], self::patterns($file, 0));
        self::assertTrue(self::allowed($file, '/x'));
    }

    public function testT12EmptyAllowIsNotARule(): void
    {
        $file = $this->parse('empty-allow.txt');
        self::assertSame([], self::patterns($file, 0));
    }

    public function testT13PathWithoutLeadingSlash(): void
    {
        $file = $this->parse('no-leading-slash.txt');
        self::assertSame(['/private', '*.pdf'], self::patterns($file, 0));
        self::assertFalse(self::allowed($file, '/private/a'));
        self::assertFalse(self::allowed($file, '/a.pdf'));
    }

    public function testT14ProductTokenFromVersionedAgent(): void
    {
        $file = $this->parse('agent-with-version.txt');
        self::assertSame(['ExampleBot'], $file->groups[0]->agents);
        self::assertFalse(self::allowed($file, '/versioned'));
    }

    public function testT15SizeLimitDiscardsCutLine(): void
    {
        $line = "# padding padding padding padding padding padding padding padding\n";
        $body = "User-agent: ExampleBot\n";
        while (strlen($body) < 400 * 1024) {
            $body .= $line;
        }
        $body .= "Disallow: /kept\n";
        // Fill to just before the limit, then a rule that straddles 512000.
        while (strlen($body) + strlen($line) < Parser::MIN_BYTES - 5) {
            $body .= $line;
        }
        $body .= str_repeat('#', Parser::MIN_BYTES - 5 - strlen($body)) . "\n";
        $body .= "Disallow: /cut-in-half\n";
        while (strlen($body) < 550 * 1024) {
            $body .= $line;
        }
        $body .= "Disallow: /past-limit\n";
        while (strlen($body) < 600 * 1024) {
            $body .= $line;
        }

        $file = (new Parser())->parse($body);
        self::assertTrue($file->truncated);
        self::assertLessThanOrEqual(Parser::MIN_BYTES, $file->bytesRead);
        self::assertSame(['/kept'], self::patterns($file, 0));
        self::assertTrue(self::allowed($file, '/cut-in-half'));
        self::assertTrue(self::allowed($file, '/cut'));
        self::assertTrue(self::allowed($file, '/past-limit'));
    }

    public function testT15LimitIsNeverBelow500KiB(): void
    {
        self::assertSame(Parser::MIN_BYTES, (new Parser(1000))->maxBytes());
        self::assertSame(700000, (new Parser(700000))->maxBytes());
    }

    public function testT16InvalidUtf8DoesNotAbort(): void
    {
        $file = $this->parse('invalid-utf8.txt');
        self::assertSame(['/good', '/bad%FF%FEpath', '/caf%C3%A9'], self::patterns($file, 0));
        self::assertFalse(self::allowed($file, '/good'));
        self::assertFalse(self::allowed($file, "/bad\xFF\xFEpath"));
        self::assertFalse(self::allowed($file, '/café'));
    }

    public function testT17MalformedLinesKeepEveryGoodRule(): void
    {
        $file = $this->parse('malformed-mix.txt');
        self::assertSame(['/one', '/two', '/three'], self::patterns($file, 0));
    }

    public function testT18RuleCapDropsAndCounts(): void
    {
        $body = "User-agent: *\n";
        for ($i = 0; $i < 10050; $i++) {
            $body .= "Disallow: /r{$i}\$\n";
        }
        $file = (new Parser())->parse($body);
        self::assertCount(10000, $file->groups[0]->rules);
        self::assertSame(50, $file->rulesDropped);
        self::assertFalse(self::allowed($file, '/r9999'));
        self::assertTrue(self::allowed($file, '/r10000'));
    }

    public function testT19HtmlPageYieldsNoGroups(): void
    {
        $file = $this->parse('html-page.txt');
        self::assertSame([], $file->groups);
        self::assertTrue(self::allowed($file, '/anything'));
    }

    public function testT20TokenComparedCaseInsensitively(): void
    {
        $file = $this->parse('token-case.txt');
        self::assertFalse(self::allowed($file, '/lower'));
    }

    public function testT21NoPrefixOrSubstringTokenMatch(): void
    {
        $file = $this->parse('token-prefix.txt');
        self::assertTrue(self::allowed($file, '/x'));
        self::assertFalse(self::allowed($file, '/x', 'Example'));
    }

    public function testT22NamedGroupsMerge(): void
    {
        $file = $this->parse('merge.txt');
        self::assertFalse(self::allowed($file, '/first'));
        self::assertFalse(self::allowed($file, '/second'));
        self::assertTrue(self::allowed($file, '/other'));
    }

    public function testT23StarGroupsMerge(): void
    {
        $file = $this->parse('star-only.txt');
        self::assertFalse(self::allowed($file, '/star1'));
        self::assertFalse(self::allowed($file, '/star2'));
        self::assertTrue(self::allowed($file, '/other'));
    }

    public function testT24EmptyFileAllowsEverything(): void
    {
        $file = (new Parser())->parse('');
        self::assertSame([], $file->groups);
        self::assertTrue(self::allowed($file, '/'));
        self::assertTrue(self::allowed($file, '/anything'));
    }

    public function testT25StarAndNamedFromOneParse(): void
    {
        $file = $this->parse('star-and-named.txt');
        self::assertFalse(self::allowed($file, '/named', 'ExampleBot'));
        self::assertTrue(self::allowed($file, '/star', 'ExampleBot'));
        self::assertTrue(self::allowed($file, '/named', 'OtherBot'));
        self::assertFalse(self::allowed($file, '/star', 'OtherBot'));
    }

    public function testMisspeltKeysAcceptedAndReported(): void
    {
        $file = $this->parse('misspellings.txt');
        self::assertSame(['dissallow', 'disalow', 'alow'], $file->misspellings);
        self::assertFalse(self::allowed($file, '/misspelt/x'));
        self::assertFalse(self::allowed($file, '/also'));
        self::assertTrue(self::allowed($file, '/misspelt/ok'));
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function tokens(): array
    {
        return [
            'plain' => ['ExampleBot', 'ExampleBot'],
            'version' => ['ExampleBot/2.1', 'ExampleBot'],
            'dash underscore' => ['Example_Bot-News 1.0', 'Example_Bot-News'],
            'wildcard' => ['*', '*'],
            'wildcard with suffix' => ['*bot', null],
            'digits first' => ['2Bot', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('tokens')]
    public function testP14ProductToken(string $value, ?string $expected): void
    {
        self::assertSame($expected, Parser::productToken($value));
    }

    public function testAgentLineWithNoTokenStillStartsAGroup(): void
    {
        $file = (new Parser())->parse("User-agent: ExampleBot\nDisallow: /a\nUser-agent: 123\nDisallow: /b\n");
        self::assertCount(2, $file->groups);
        self::assertTrue(self::allowed($file, '/b'));
        self::assertSame([], $file->groups[1]->agents);
    }

    public function testRoundTripThroughArray(): void
    {
        $file = $this->parse('merge.txt');
        $copy = RobotsFile::fromArray($file->toArray());
        self::assertEquals($file->toArray(), $copy->toArray());
        self::assertFalse(self::allowed($copy, '/second'));
    }

    public function testLongWhitespaceLineDoesNotDropLaterRules(): void
    {
        // A regex scan of the body hit the PCRE backtrack limit on a line like
        // this and silently returned no rules after it (P17).
        $body = "User-agent: *\nDisallow: /early\nSitemap: https://x.example/s.xml"
            . str_repeat(' ', 20000) . "x\nDisallow: /pad" . str_repeat("\t ", 20000) . "\nDisallow: /late\n";
        $file = (new Parser())->parse($body);
        self::assertSame(['/early', '/pad', '/late'], self::patterns($file, 0));
        self::assertFalse(self::allowed($file, '/late/1'));
    }

    public function testKeyAndValueSplitAtFirstColonAndHash(): void
    {
        $file = (new Parser())->parse("User-agent:ExampleBot\n  Disallow :\t/a:b?c=1 # note: x\n:/nokey\nDisallow: #/c\n");
        self::assertSame(['/a:b?c=1'], self::patterns($file, 0));
    }
}
