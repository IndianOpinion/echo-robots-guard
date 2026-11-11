# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-11

### Added

- RFC 9309 robots.txt parser and matcher with no WordPress dependency:
  groups, exact product-token match, merged groups, `*` fallback, longest
  match with Allow on a tie, `*` and `$`, percent-encoding normalisation,
  a 500 KiB parse limit, a 10,000-rule cap and linear-time matching.
- Callback on Echo's public `echo_filter_dup_check` filter at priority 20:
  passes through an earlier `true`, ignores values that are not http(s)
  URLs, decodes HTML entities, and never returns `false`.
- Modes `enforce` (default), `shadow` and `off`; failure policy `closed`
  (default) or `open` for unreachable robots.txt.
- HTTP handling: 4xx allows all, 429 and 5xx and network errors are
  unreachable, up to five redirects followed, unsafe destinations refused.
- Network-wide cache table with the object cache in front, 24-hour TTL,
  1-hour error TTL, 30-day stale copy window, daily purge.
- Per-request fetch cap (20 by default) and one fetch per origin per
  request.
- Accepted misspellings of Disallow and Allow, counted and logged.
- Public API: `may_fetch()`, the `echo_robots_guard_may_fetch` filter and
  the `Decision` value object.
- `[echo_robots_guard_statement]` shortcode and `statement()` function for
  a truthful /bot page.
- Tools page with settings, counters, blocked log, cache list and cache
  clearing; constants to fix settings for must-use installs.
- WP-CLI: `check` (with standard input), `cache list|clear`, `stats`,
  `log`, `status`.
- Counters and blocked log per site, buffered and written once per request.
- Uninstall that removes every option, the table and the schedule on every
  site.

[Unreleased]: https://github.com/IndianOpinion/echo-robots-guard/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/echo-robots-guard/releases/tag/v0.1.0
