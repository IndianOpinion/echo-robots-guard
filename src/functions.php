<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard;

/**
 * May this product token fetch this URL? Answers the robots.txt truth in
 * every mode (A2). $token defaults to the configured product token (A4).
 *
 * @throws Exception\InvalidTokenException When $token is not a valid product token.
 */
function may_fetch(string $url, ?string $token = null): Decision
{
    return Plugin::instance()->mayFetch($url, $token);
}

/**
 * The public statement for the /bot page, as HTML, matching the live mode.
 */
function statement(): string
{
    return Plugin::instance()->statement->html();
}
