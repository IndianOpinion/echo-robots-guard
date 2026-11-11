<?php

declare(strict_types=1);

namespace IndianOpinion\EchoRobotsGuard;

use IndianOpinion\EchoRobotsGuard\Exception\InvalidTokenException;
use IndianOpinion\EchoRobotsGuard\Robots\Matcher;
use IndianOpinion\EchoRobotsGuard\Robots\Parser;
use IndianOpinion\EchoRobotsGuard\Robots\Target;

/**
 * Answers "may this token fetch this URL?" from cached or freshly fetched
 * robots.txt. Knows nothing about Echo; the integration and the public API
 * both call check().
 *
 * Per-request state: every origin is fetched at most once per request even
 * if the cache cannot be written (H16, C10), and at most max_fetches origins
 * are fetched per request (decision 6).
 */
class Guard
{
    /** @var array<string, CacheEntry> */
    private array $memo = [];

    /** @var array<string, true> */
    private array $fetchedNow = [];

    private int $fetchCount = 0;

    public function __construct(
        private Settings $settings,
        private Cache $cache,
        private Fetcher $fetcher,
        private Recorder $recorder,
        private Logger $logger,
        private Clock $clock,
        private ?Parser $parser = null
    ) {
    }

    /**
     * @param string      $context "echo" or "api" (A5).
     * @param bool        $fresh   Ignore the cache and refetch (CLI --fresh).
     *
     * @throws InvalidTokenException When $token is not a valid product token.
     */
    public function check(string $url, ?string $token = null, string $context = 'api', bool $fresh = false): Decision
    {
        if ($token !== null && !Settings::isValidToken($token)) {
            throw InvalidTokenException::for($token);
        }
        $token ??= $this->settings->token();

        $target = Target::fromUrl($url);
        if ($target === null) {
            $this->recorder->count($context, 'not_checked');

            return $this->emit(Decision::notChecked($url, Decision::NOT_HTTP_URL, null, $token), $context);
        }
        if ($this->settings->isSkipped($target->host)) {
            $this->recorder->count($context, 'not_checked');

            return $this->emit(
                Decision::notChecked($url, Decision::SKIP_LIST, $target->origin(), $token),
                $context
            );
        }

        $this->recorder->count($context, 'checked');
        $decision = $this->decide($url, $target, $token, $context, $fresh);
        $this->recorder->count($context, $decision->allowed ? 'allowed' : 'disallowed');

        return $this->emit($decision, $context);
    }

    /**
     * Forget per-request state. The plugin never needs this; long-running
     * workers and tests do.
     */
    public function resetRequest(): void
    {
        $this->memo = [];
        $this->fetchedNow = [];
        $this->fetchCount = 0;
    }

    private function decide(string $url, Target $target, string $token, string $context, bool $fresh): Decision
    {
        $origin = $target->origin();
        $now = $this->clock->now();
        $policy = $this->settings->failPolicy();

        $entry = null;
        $state = 'miss';
        if (isset($this->memo[$origin]) && (!$fresh || isset($this->fetchedNow[$origin]))) {
            $entry = $this->memo[$origin];
            $state = isset($this->fetchedNow[$origin]) && $fresh ? 'miss' : 'hit';
        }

        $previous = null;
        if ($entry === null) {
            $previous = $this->cache->get($origin);
            if (!$fresh && $previous !== null && $previous->isFresh($now)) {
                $entry = $previous;
                $state = 'hit';
                $this->memo[$origin] = $entry;
            }
        }

        if ($state === 'hit') {
            $this->recorder->count($context, 'cache_hits');
        } elseif ($entry === null) {
            $this->recorder->count($context, 'cache_misses');
            if ($this->fetchCount >= $this->settings->maxFetches()) {
                $this->recorder->count($context, 'fetch_cap_reached');
                if ($previous !== null && $previous->hasStaleCopy($now, $this->settings->staleWindow())) {
                    return $this->fromRules($url, $previous, $token, $context, 'stale', Decision::STALE_COPY);
                }

                return new Decision(
                    $url,
                    $policy === 'open',
                    Decision::FETCH_CAP,
                    null,
                    $origin,
                    'miss',
                    $token,
                    $policy
                );
            }
            $entry = $this->fetch($target, $previous, $context, $token);
        }

        if ($entry->outcome === CacheEntry::OK) {
            return $this->fromRules($url, $entry, $token, $context, $state, null);
        }
        if ($entry->outcome === CacheEntry::UNAVAILABLE) {
            return new Decision($url, true, Decision::UNAVAILABLE, null, $origin, $state, $token, $policy);
        }
        if ($entry->hasStaleCopy($now, $this->settings->staleWindow())) {
            return $this->fromRules($url, $entry, $token, $context, 'stale', Decision::STALE_COPY);
        }

        return new Decision($url, $policy === 'open', Decision::UNREACHABLE, null, $origin, $state, $token, $policy);
    }

    private function fromRules(
        string $url,
        CacheEntry $entry,
        string $token,
        string $context,
        string $state,
        ?string $reason
    ): Decision {
        $target = Target::fromUrl($url);
        $rules = $entry->rules;
        if ($target === null || $rules === null) {
            return new Decision($url, true, Decision::NO_RULE, null, $entry->origin, $state, $token);
        }
        if ($reason === Decision::STALE_COPY) {
            $this->recorder->count($context, 'stale_used');
        }
        $result = Matcher::decide($rules, $token, $target->path);
        $rule = $result->rule === null ? null : [
            'agent' => $result->agent,
            'directive' => $result->rule->directive(),
            'pattern' => $result->rule->pattern,
        ];
        if ($reason === null) {
            if ($rule !== null) {
                $reason = Decision::RULE;
            } else {
                $path = strstr($target->path, '?', true);
                $reason = ($path === false ? $target->path : $path) === '/robots.txt'
                    ? Decision::ROBOTS_TXT
                    : Decision::NO_RULE;
            }
        }

        return new Decision($url, $result->allowed, $reason, $rule, $entry->origin, $state, $token);
    }

    private function fetch(Target $target, ?CacheEntry $previous, string $context, string $token): CacheEntry
    {
        $origin = $target->origin();
        $this->fetchCount++;
        $result = $this->fetcher->fetch($target, $token);
        $now = $this->clock->now();
        $this->recorder->count($context, 'fetches');

        $rules = null;
        $rulesAt = null;
        $bytes = 0;
        $unreachableSince = null;
        $ttl = $this->settings->ttl();

        if ($result->outcome === CacheEntry::OK) {
            $parser = $this->parser ?? new Parser($this->settings->maxBytes(), $this->settings->maxRules());
            $rules = $parser->parse($result->body);
            $rulesAt = $now;
            $bytes = $rules->bytesRead;
            if ($rules->rulesDropped > 0) {
                $this->recorder->count($context, 'rules_dropped', $rules->rulesDropped);
                $this->logger->error(sprintf('%s: rule cap reached, %d rules dropped.', $origin, $rules->rulesDropped));
            }
            if ($rules->misspellings !== []) {
                $this->recorder->count($context, 'misspellings_accepted', count($rules->misspellings));
                $this->logger->error(sprintf(
                    '%s: accepted misspelt keys: %s.',
                    $origin,
                    implode(', ', $rules->misspellings)
                ));
            }
        } elseif ($result->outcome === CacheEntry::UNREACHABLE) {
            $ttl = $this->settings->errorTtl();
            if ($previous !== null && $previous->rules !== null) {
                $rules = $previous->rules;
                $rulesAt = $previous->rulesFetchedAt;
            }
            $unreachableSince = $previous !== null && $previous->outcome === CacheEntry::UNREACHABLE
                ? ($previous->unreachableSince ?? $previous->fetchedAt)
                : $now;
        }

        if ($result->errorClass !== null) {
            $this->recorder->count($context, 'fetch_errors');
            $this->recorder->count($context, 'fetch_error_' . $result->errorClass);
            $firstToday = $previous === null
                || $previous->errorClass === null
                || gmdate('Y-m-d', $previous->fetchedAt) !== gmdate('Y-m-d', $now);
            if ($firstToday) {
                $this->logger->error(sprintf(
                    '%s/robots.txt: fetch error "%s"%s; treated as %s.',
                    $origin,
                    $result->errorClass,
                    $result->status === null ? '' : ' (HTTP ' . $result->status . ')',
                    $result->outcome
                ));
            }
        }

        $entry = new CacheEntry(
            $origin,
            $result->outcome,
            $result->status,
            $result->errorClass,
            $rules,
            $bytes,
            $now,
            $now + $ttl,
            $rulesAt,
            $unreachableSince
        );

        if (!$this->cache->put($entry)) {
            $this->recorder->count($context, 'cache_write_errors');
        }
        $this->memo[$origin] = $entry;
        $this->fetchedNow[$origin] = true;

        do_action('echo_robots_guard_fetched', $origin, $result->outcome, $result->status, $bytes);

        return $entry;
    }

    private function emit(Decision $decision, string $context): Decision
    {
        do_action('echo_robots_guard_decision', $decision->url, $decision, $context);

        return $decision;
    }
}
