<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard;

/**
 * The callback on Echo's public filter "echo_filter_dup_check".
 *
 * Echo applies the filter to each item's URL before it downloads the
 * article page and skips the item when a callback returns true. This
 * callback returns either the value it received or true; never false, and
 * never anything else (E10). Any fault inside is caught here (E9).
 */
final class EchoIntegration
{
    public const FILTER = 'echo_filter_dup_check';

    public const DEFAULT_PRIORITY = 20;

    public function __construct(
        private Settings $settings,
        private Guard $guard,
        private Recorder $recorder,
        private Logger $logger
    ) {
    }

    /**
     * Reads the priority once, at load time (E1).
     */
    public static function priority(): int
    {
        $priority = apply_filters('echo_robots_guard_priority', self::DEFAULT_PRIORITY);

        return is_numeric($priority) ? (int) $priority : self::DEFAULT_PRIORITY;
    }

    public function register(): void
    {
        add_filter(self::FILTER, [$this, 'filter'], self::priority(), 1);
    }

    public function filter(mixed $value): mixed
    {
        try {
            $mode = $this->settings->mode();
            if ($mode === 'off') {
                return $value;
            }
            $this->recorder->seenEcho();

            if ($value === true) {
                // Another callback (a duplicate check) already skips it (E2).
                $this->recorder->count('echo', 'passed_through');

                return true;
            }
            if (!is_string($value)) {
                return $value;
            }
            $url = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (preg_match('#^https?://#i', $url) !== 1) {
                // A title or anything else that is not an http(s) URL (E3).
                return $value;
            }

            $decision = $this->guard->check($url, null, 'echo');
            if ($decision->allowed !== false) {
                return $value;
            }

            $this->recorder->count('echo', $mode === 'enforce' ? 'blocked' : 'would_block');
            $this->recorder->log([
                'url' => $url,
                'origin' => $decision->origin,
                'rule' => $decision->ruleText(),
                'reason' => $decision->reason,
                'mode' => $mode,
                'context' => 'echo',
            ]);
            do_action('echo_robots_guard_blocked', $url, $decision->rule ?? $decision->ruleText(), $mode, $decision);

            return $mode === 'enforce' ? true : $value;
        } catch (\Throwable $e) {
            $this->recorder->count('echo', 'internal_errors');
            $this->logger->error(sprintf(
                'internal error, item let through: %s: %s',
                get_class($e),
                $e->getMessage()
            ));

            return $value;
        }
    }
}
