<?php

/**
 * The parser and matcher without WordPress.
 *
 * Run: php examples/check-robots-standalone.php
 * Or with your own file: php examples/check-robots-standalone.php robots.txt ExampleBot /some/path
 *
 * @package IndianOpinion\EchoRobotsGuard
 */

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use IndianOpinion\EchoRobotsGuard\Robots\Matcher;
use IndianOpinion\EchoRobotsGuard\Robots\Parser;
use IndianOpinion\EchoRobotsGuard\Robots\Target;

$body = <<<'ROBOTS'
User-agent: ExampleBot
Disallow: /private/
Allow: /private/press-releases/
Disallow: /*?utm_

User-agent: *
Disallow: /search
ROBOTS;

$token = 'ExampleBot';
$urls = [
    'https://news.example/politics/story-1',
    'https://news.example/private/notes',
    'https://news.example/private/press-releases/launch',
    'https://news.example/story?utm_source=feed',
    'https://news.example/robots.txt',
];

if ($argc >= 4) {
    $read = file_get_contents($argv[1]);
    if ($read === false) {
        fwrite(STDERR, "Cannot read {$argv[1]}\n");
        exit(2);
    }
    $body = $read;
    $token = $argv[2];
    $urls = array_map(static fn (string $p): string => 'https://example.invalid' . $p, array_slice($argv, 3));
}

$file = (new Parser())->parse($body);

foreach ($urls as $url) {
    $target = Target::fromUrl($url);
    if ($target === null) {
        echo "not checked  {$url}\n";
        continue;
    }
    $result = Matcher::decide($file, $token, $target->path);
    $why = $result->rule === null
        ? 'no rule matched'
        : sprintf('%s %s (group %s)', ucfirst($result->rule->directive()), $result->rule->pattern, $result->agent ?? '-');
    printf("%-11s  %s  [%s]\n", $result->allowed ? 'allowed' : 'disallowed', $target->path, $why);
}
