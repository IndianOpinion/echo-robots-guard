<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard;

/**
 * The public statement for the operator's /bot page (T1 to T7). Generated
 * from the live settings every time, so it cannot claim more than the code
 * does.
 */
final class Statement
{
    public const SHORTCODE = 'echo_robots_guard_statement';

    public function __construct(private Settings $settings)
    {
    }

    public function html(): string
    {
        $s = $this->settings;
        $mode = $s->mode();
        $token = $s->token();
        $policy = $s->failPolicy();
        $skips = $s->skipHosts();

        $p = [];

        if ($mode === 'enforce') {
            $p[] = sprintf(
                /* translators: %s: product token */
                esc_html__('Before this site downloads an article page found in a news feed, it reads the publisher\'s robots.txt and checks the page against the rules for the product token %s. Pages that robots.txt disallows for that token are not downloaded.', 'echo-robots-guard'),
                '<code>' . esc_html($token) . '</code>'
            );
        } elseif ($mode === 'shadow') {
            $p[] = sprintf(
                /* translators: %s: product token */
                esc_html__('This site is trialling a robots.txt check for the product token %s. Article pages that robots.txt disallows for that token are recorded for review, but they are not yet skipped.', 'echo-robots-guard'),
                '<code>' . esc_html($token) . '</code>'
            );
        } else {
            $p[] = sprintf(
                /* translators: %s: product token */
                esc_html__('The robots.txt check for the product token %s is switched off on this site at the moment.', 'echo-robots-guard'),
                '<code>' . esc_html($token) . '</code>'
            );
        }

        if ($mode !== 'off') {
            $p[] = sprintf(
                /* translators: %s: user agent string */
                esc_html__('robots.txt is fetched with the user agent %s. The article downloads themselves are made by the importer and may carry a different user agent, so please write your rules for the product token.', 'echo-robots-guard'),
                '<code>' . esc_html($s->userAgent()) . '</code>'
            );
            $p[] = esc_html__('The check follows RFC 9309: the group that names the token is used, otherwise the "*" group; the longest matching rule wins; on a tie between Allow and Disallow, Allow wins.', 'echo-robots-guard');

            $unreachable = $policy === 'open'
                ? __('If robots.txt cannot be reached (a server error, a timeout, or 429 Too Many Requests), it is treated as allowing access. In this respect the site does not follow RFC 9309, which asks for complete disallow.', 'echo-robots-guard')
                : __('If robots.txt cannot be reached (a server error, a timeout, or 429 Too Many Requests), every page on that site is treated as disallowed, unless a recent copy of the file is held.', 'echo-robots-guard');
            $p[] = esc_html__('A robots.txt that answers 404 or another 4xx status is treated as allowing everything.', 'echo-robots-guard')
                . ' ' . esc_html($unreachable);

            $p[] = esc_html(sprintf(
                /* translators: 1: hours, 2: minutes */
                esc_html__('robots.txt is cached for %1$s hours, or %2$s minutes after an error.', 'echo-robots-guard'),
                self::number($s->ttl() / 3600),
                self::number($s->errorTtl() / 60)
            ));

            if ($skips !== []) {
                $named = apply_filters('echo_robots_guard_statement_name_skip_hosts', false);
                $p[] = $named === true
                    ? sprintf(
                        /* translators: %s: list of host names */
                        esc_html__('Links on these hosts are not checked, because their robots.txt speaks for a redirect service rather than the publisher: %s.', 'echo-robots-guard'),
                        esc_html(implode(', ', $skips))
                    )
                    : esc_html__('Links on some hosts are not checked, because their robots.txt speaks for a redirect service rather than the publisher.', 'echo-robots-guard');
            }
        }

        $p[] = esc_html__('The news feeds themselves are fetched without a robots.txt check; only article pages are covered.', 'echo-robots-guard');

        $optOut = sprintf(
            /* translators: %s: robots.txt lines */
            esc_html__('To opt out, add these lines to your robots.txt: %s', 'echo-robots-guard'),
            '<code>User-agent: ' . esc_html($token) . '</code> <code>Disallow: /</code>'
        );
        $email = $s->contactEmail();
        if ($email !== '') {
            $optOut .= ' ' . sprintf(
                /* translators: %s: email address */
                esc_html__('To have a feed removed, write to %s.', 'echo-robots-guard'),
                '<a href="' . esc_url('mailto:' . $email) . '">' . esc_html($email) . '</a>'
            );
        }
        $p[] = $optOut;

        $html = '<div class="echo-robots-guard-statement"><p>' . implode('</p><p>', $p) . '</p></div>';

        $settings = [
            'mode' => $mode,
            'token' => $token,
            'fail_policy' => $policy,
            'ttl' => $s->ttl(),
            'error_ttl' => $s->errorTtl(),
            'skip_hosts' => $skips,
            'user_agent' => $s->userAgent(),
            'contact_email' => $email,
        ];
        $filtered = apply_filters('echo_robots_guard_statement', $html, $mode, $settings);

        return is_string($filtered) ? $filtered : $html;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
