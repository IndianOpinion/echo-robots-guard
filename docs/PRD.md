# echo-robots-guard: product requirements

Status: draft for review. Version 0.1.0 target.
Normative reference: RFC 9309, "Robots Exclusion Protocol", September 2022,
https://www.rfc-editor.org/rfc/rfc9309. Section numbers below such as
"RFC 9309 2.2.2" point into it.

The words MUST, SHOULD and MAY in this document mean what RFC 2119 says
they mean.

## 1. Problem

A WordPress site that imports news with the Echo RSS Post Importer reads a
feed, then downloads each linked article page. Nothing in that path reads
the publisher's robots.txt. The site operator cannot honestly tell
publishers "we honour your robots.txt" on a /bot page, and a publisher who
writes `User-agent: YourBot` and `Disallow: /` is ignored.

Measured result: an audit of the site's feed rules found that a meaningful
share pointed at paths the publishers' robots.txt disallowed, which is what
made a guard at the download step necessary.

Echo exposes one public, documented point before it downloads an article
page: the filter `echo_filter_dup_check`. It is applied to each item's URL,
and the item is skipped when a callback returns `true`. That is the only
vendor fact this project relies on. This plugin uses that filter to answer
"does robots.txt let this product token fetch this URL?" and, when the
answer is no, to skip the item.

## 2. Goals and non-goals

### Goals

1. Before Echo downloads an article page, check the URL against the host's
   robots.txt under RFC 9309 and skip the item when it is disallowed for
   the configured product token.
2. Implement the RFC 9309 parsing and matching rules completely: groups,
   product token matching, merging of groups, Allow and Disallow with the
   longest match winning, `*` and `$`, percent-encoding normalisation, the
   500 KiB parsing limit, and the status-code semantics (4xx allows all,
   5xx and network errors are "unreachable").
3. Cache each host's rules with a configurable TTL (default 24 hours).
4. Give the operator a configurable failure policy for unreachable
   robots.txt: fail closed (the RFC default) or fail open.
5. Give the operator a shadow mode: record what would be skipped without
   skipping, so the rules can be reviewed before they bite.
6. Show the operator that it works: counters, a log of blocked URLs with
   the rule that matched, an admin page, WP-CLI commands.
7. Offer a general function and filter so any importer, not only Echo, can
   ask "may I fetch this URL?".
8. Make the operator's /bot page truthful: a shortcode and a function
   produce a short statement that always matches the mode the plugin is in.
9. Work as a normal plugin, a network-activated plugin, or a must-use
   plugin.

### Non-goals

1. Editing Echo or any other importer. The plugin only hooks public filters.
2. Checking the feed URL. Echo applies no filter before it fetches the feed
   itself, so the feed request cannot be intercepted from outside. This
   limit is documented (see 4.9) with the operator-side policy that covers
   it.
3. Sitemaps. `Sitemap:` lines are parsed only so they do not break groups;
   they are not stored or used.
4. Crawl-delay. It is not part of RFC 9309. `Crawl-delay:` lines are
   ignored; the plugin does not throttle requests. This is documented in
   the README as a deliberate non-goal.
5. Rate limiting, politeness scheduling or request pacing of the importer.
6. Setting the User-Agent header of the importer's own requests. The plugin
   cannot change what Echo sends; it only controls its own robots.txt fetch.
7. Honouring HTTP cache headers (Cache-Control, Expires) on robots.txt. RFC
   9309 2.4 makes this optional; the plugin uses its own TTL.
8. Access control. robots.txt is advisory (RFC 9309 1, 3).
9. Unwrapping aggregator redirect URLs. Those are passed through (see 4.6).

## 3. Users and setting

- **Who installs it:** the operator of a WordPress site that imports
  articles from other publishers, usually with Echo, sometimes with another
  importer that can call the general API.
- **Where it runs:** inside WordPress, on the same PHP process as the
  importer. Echo runs its imports from WP-Cron or a system cron calling
  WP-CLI or `wp-cron.php`, so most checks happen in a cron context with no
  logged-in user.
- **Who reads its output:** the operator (admin page, WP-CLI, logs) and,
  indirectly, publishers who read the operator's /bot page.
- **Install forms:** a normal plugin in `wp-content/plugins/`; network
  activated on multisite; or a single file plus folder loaded from
  `wp-content/mu-plugins/` through a one-line loader. No Composer at
  runtime.

## 4. Behaviour

Every case has an identifier. Section 12 maps each one to a test.

### 4.1 Parsing (RFC 9309 2.1, 2.2, 2.3.1.5, 2.5)

- **P1. Line endings.** Lines end at LF, CR or CRLF. Mixed endings in one
  file are accepted.
- **P2. Byte order mark.** A UTF-8 BOM at the start of the file is ignored.
- **P3. Comments.** Everything from `#` to the end of the line is removed
  before the line is read. A line that is empty after that is ignored.
- **P4. Whitespace.** Spaces and tabs around the key, the colon and the
  value are ignored (RFC 9309 2.2).
- **P5. Key case.** Keys are matched case-insensitively: `User-agent`,
  `user-agent` and `USER-AGENT` are the same key.
- **P6. Lines without a colon** are ignored and do not end a group.
- **P7. Unknown keys** (`Sitemap`, `Crawl-delay`, `Host`, `Clean-param`,
  anything else) are ignored and do not end or start a group (RFC 9309
  2.2.4: other records MUST NOT interfere).
- **P8. Group start.** A `User-agent` line that follows a rule line (Allow
  or Disallow) starts a new group. Consecutive `User-agent` lines, with only
  ignored lines between them, belong to one group (RFC 9309 2.1).
- **P9. Rules before the first User-agent line** are ignored (RFC 9309
  2.2.2).
- **P10. Group with no rules.** A group whose user agents are followed by
  no Allow or Disallow line allows everything for those agents (RFC 9309
  2.1). It is still a matching group: it does not fall back to `*`.
- **P11. Empty Disallow.** `Disallow:` with an empty value is not a rule.
  It allows nothing and blocks nothing; it does make the group a group with
  zero rules (so P10 applies).
- **P12. Empty Allow.** `Allow:` with an empty value is likewise not a rule.
- **P13. Path without a leading slash.** A value that starts with `*` is
  kept as it is. Any other value that does not start with `/` gets a `/`
  prepended (lenient reading; RFC 9309 2.2 requires `/`). This is a
  documented leniency.
- **P14. User-agent value.** The product token is the leading run of
  letters, `_` and `-` in the value (RFC 9309 2.2.1). `User-agent:
  ExampleBot/2.1 (+https://x)` names `ExampleBot`. A value of exactly `*`
  is the wildcard. A value that yields an empty token and is not `*` is
  ignored.
- **P15. Size limit.** At most 500 KiB (512,000 bytes) of the body is read
  (RFC 9309 2.5: the limit MUST be at least 500 KiB). Content beyond the
  limit is ignored. A line cut by the limit is discarded, not parsed in
  part.
- **P16. Encoding.** The file is expected to be UTF-8 (RFC 9309 2.3). Bytes
  that are not valid UTF-8 do not abort the parse: each line is parsed on
  its bytes; invalid sequences inside a path are percent-encoded as raw
  octets (see M10).
- **P17. Every parseable line counts.** A malformed line never discards the
  rest of the file (RFC 9309 2.3.1.5).
- **P18. Rule count limit.** At most 10,000 rules are kept per file (a
  safety cap, configurable through a filter). Rules past the cap are
  dropped and the drop is counted and logged once per host fetch.
- **P19. HTML instead of robots.txt.** A 2xx body that is an HTML page
  (common when a site serves its home page for unknown paths) is parsed
  like any other text; it normally yields no groups, which allows all.
  No content-type check is made.

### 4.2 Selecting the group (RFC 9309 2.2.1)

- **G1. Exact token match.** A group applies when one of its user-agent
  tokens equals the configured product token, compared
  case-insensitively.
- **G2. No prefix or substring matching.** A group for `Example` does not
  apply to the token `ExampleBot`, and a group for `ExampleBotNews` does
  not apply either.
- **G3. Merge.** When several groups name the token, their rules are
  merged into one rule set (RFC 9309 2.2.1).
- **G4. Wildcard fallback.** When no group names the token, the rules of
  every `*` group, merged, apply.
- **G5. No group at all.** When neither a named nor a `*` group exists,
  every URL is allowed.
- **G6. Named group beats `*`.** When a named group exists, `*` groups are
  not consulted at all, even if the named group has no rules (P10).
- **G7. Group with several agents.** A group listing `ExampleBot` and
  `OtherBot` applies to both.
- **G8. Token change.** Changing the configured token takes effect on the
  next check without refetching: the cache stores every parsed group, and
  the group is selected at check time.

### 4.3 Matching (RFC 9309 2.2.2, 2.2.3)

- **M1. What is matched.** The URL's path plus, when present, `?` and the
  query. The fragment is dropped. An empty path is `/`.
- **M2. Prefix match.** A pattern without special characters matches any
  path that starts with it, compared octet by octet, case-sensitively.
- **M3. Longest match wins.** Among all Allow and Disallow rules that
  match, the one with the longest pattern (in octets, after
  normalisation, counting `*` and `$` as written) decides.
- **M4. Tie goes to Allow.** When an Allow and a Disallow of equal length
  both match, the URL is allowed (RFC 9309 2.2.2: SHOULD use allow).
- **M5. No match allows.** When no rule matches, the URL is allowed.
- **M6. Wildcard.** `*` matches zero or more of any octet, anywhere in the
  pattern. Several `*` in a row behave like one.
- **M7. End anchor.** A `$` as the last character anchors the match to the
  end of the path plus query. A `$` anywhere else is a literal character.
- **M8. Disallow everything.** `Disallow: /` blocks every path, including
  `/` itself, unless a longer Allow matches.
- **M9. robots.txt itself.** `/robots.txt` is always allowed (RFC 9309
  2.2.2), whatever the rules say.
- **M10. Percent-encoding normalisation.** Before comparison, both the
  pattern and the URL path are normalised the same way: percent-encoded
  octets of unreserved characters (`A-Z a-z 0-9 - . _ ~`) are decoded;
  all other percent escapes keep their encoding with the hex digits
  uppercased; octets outside printable ASCII (including UTF-8 bytes of
  non-ASCII characters) are percent-encoded (RFC 9309 2.2.2). So
  `/caf%C3%A9`, `/café` and `/caf%c3%a9` are the same path, and `/%7Efoo`
  equals `/~foo`.
- **M11. Reserved characters stay encoded.** `%2F` is not decoded to `/`,
  so `Disallow: /a/b` does not match `/a%2Fb`.
- **M12. Literal `*` and `$`.** A path that contains a real `*` or `$` can
  be matched only when the pattern writes them percent-encoded (`%2A`,
  `%24`), as RFC 9309 2.2.3 suggests. The URL side is normalised so `%2A`
  stays `%2A`.
- **M13. Query matters.** `Disallow: /*?utm_` blocks `/story?utm_source=x`
  and allows `/story`.
- **M14. Case-sensitive paths.** `Disallow: /Private` does not block
  `/private`.
- **M15. Linear-time matching.** Matching never uses backtracking that can
  explode on patterns like `/*a*a*a*a*b`. A 10,000-rule file against a
  2,000-byte path finishes inside the budget in section 9.

### 4.4 Fetching robots.txt and HTTP status (RFC 9309 2.3)

- **H1. Location.** robots.txt is fetched from `scheme://host[:port]/robots.txt`
  of the item URL. Scheme, host and port each make a distinct origin:
  `http://a.example` and `https://a.example` are checked separately, as
  are `a.example` and `www.a.example`.
- **H2. Default ports.** `https://a.example:443` and `https://a.example`
  are the same origin; so are `http://a.example:80` and `http://a.example`.
- **H3. Host normalisation.** Host names are lowercased. IPv6 literals keep
  their brackets. Internationalised host names are compared in their ASCII
  (punycode) form when WordPress provides it, otherwise as given. Userinfo
  in the URL is ignored and never sent.
- **H4. 2xx.** The body is parsed (section 4.1) and the rules are followed.
  An empty body allows everything.
- **H5. Redirects.** Up to five redirects are followed, across hosts if
  needed (RFC 9309 2.3.1.2). The file found is applied to the original
  origin, not to the host it was found on.
- **H6. Too many redirects.** More than five redirects count as
  "unavailable" (allow all), as RFC 9309 2.3.1.2 permits.
- **H7. Redirect to a non-HTTP scheme** is not followed and counts as
  unreachable.
- **H8. 4xx.** 400 to 499, including 401, 403, 404 and 410, mean
  "unavailable": every URL on the origin is allowed (RFC 9309 2.3.1.3).
- **H9. 429 Too Many Requests** is treated as unreachable (H10), not as
  unavailable. It means "come back later", not "there is no file". This is
  a documented reading beyond the RFC text.
- **H10. Unreachable.** 5xx, a timeout, a DNS failure, a refused
  connection, a TLS failure, or any status outside 2xx, 3xx and 4xx means
  "unreachable". RFC 9309 2.3.1.4 says to assume complete disallow. The
  result is then decided by the failure policy (section 4.8).
- **H11. Stale copy while unreachable.** When the origin is unreachable
  and a previously parsed 2xx copy for it is still held (within the stale
  window, default 30 days), that copy is used instead of the failure
  policy (RFC 9309 2.4 allows a cached copy past 24 hours when the file is
  unreachable). The use of a stale copy is counted.
- **H12. Timeout.** The fetch times out after 8 seconds by default
  (configurable, 1 to 30).
- **H13. Request identity.** The fetch sends a User-Agent of the form
  `<token>/<plugin version> (+<bot page URL>)`. The bot page URL is
  configurable and defaults to the site's home URL plus `bot`. No cookies,
  no referrer, no credentials are sent.
- **H14. Unsafe destinations.** The fetch refuses private, loopback and
  link-local addresses (the WordPress "safe" HTTP request rules), so a feed
  item cannot make the site probe its own network. A refused fetch counts
  as unreachable. A filter lets an operator allow named internal hosts.
- **H15. Non-HTTP item URLs** (`ftp:`, `mailto:`, relative URLs, empty
  strings, anything without a host) are not checked: the plugin answers
  "not checked" and the filter value passes through.
- **H16. One fetch per origin per request.** Within one PHP request, an
  origin is fetched at most once, even when the persistent cache is
  unavailable or a write to it failed.

### 4.5 Cache

- **C1. Key.** One entry per origin (scheme, host, port). The token is not
  part of the key (G8).
- **C2. What is stored.** The parsed groups, the outcome (ok, unavailable,
  unreachable), the HTTP status or error class, the fetch time, the
  expiry, and the byte count read. Not the raw body.
- **C3. TTL after 2xx or 4xx.** Default 24 hours, configurable from 5
  minutes to 7 days. A value above 24 hours is accepted with a warning on
  the admin page, because RFC 9309 2.4 says a crawler SHOULD NOT use a
  cached copy for more than 24 hours.
- **C4. TTL after unreachable.** Default 1 hour, configurable, never longer
  than the success TTL. An error must not block a host for a whole day.
- **C5. Hit.** A fresh entry answers without a network request and counts
  a cache hit.
- **C6. Miss or expiry.** The file is fetched, parsed and stored, and the
  answer comes from the new entry.
- **C7. Stale copy kept.** The last 2xx copy is kept for the stale window
  (H11) even after its TTL has passed, so it can stand in while the origin
  is unreachable. When a fresh 2xx or 4xx arrives it replaces the old copy.
- **C8. Clear.** The operator can clear the whole cache or one origin, from
  the admin page and from WP-CLI. The next check refetches.
- **C9. Object cache.** With a persistent object cache, entries live there;
  without one, in the database. Both work. Eviction is harmless: it only
  causes a refetch.
- **C10. Write failure.** A failed cache write does not change the answer;
  H16 still prevents repeat fetches in the same request.
- **C11. Settings change.** Changing the failure policy, mode or token does
  not need a cache clear. Changing the TTL applies to entries written after
  the change.
- **C12. Size.** Parsed entries are bounded by P15 and P18. The number of
  origins held is not capped by the plugin; each entry expires on its own.

### 4.6 The Echo integration and its interplay with a duplicate check

- **E1. Hook.** The plugin adds one callback to `echo_filter_dup_check` at
  priority 20 by default (configurable by a filter read once at load time).
  Duplicate-check callbacks conventionally use the default priority 10, so
  they run first.
- **E2. Already skipped.** When the incoming value is exactly `true`
  (another callback already decided to skip), it is returned untouched. No
  robots.txt is fetched and nothing is counted except "passed through".
- **E3. Not a URL.** Echo may call the filter with a value that is not a
  URL (for example a title, when its title check is on), or with a
  non-string. Anything that is not a string holding an http or https URL
  is returned untouched and not counted as checked.
- **E4. Entity-encoded URLs.** Feed URLs often arrive with HTML entities
  (`&amp;` in the query). The value is HTML-entity-decoded and trimmed
  before it is parsed. The value returned is always the original, never
  the decoded copy.
- **E5. Allowed.** The original value is returned unchanged.
- **E6. Disallowed, enforce mode.** `true` is returned, so Echo skips the
  item. The block is counted and logged (section 11).
- **E7. Disallowed, shadow mode.** The original value is returned (nothing
  is skipped). A "would block" is counted and logged.
- **E8. Off mode.** The callback returns the value at once. Nothing is
  fetched or counted. The general API (section 5) still answers.
- **E9. Our own fault.** Any exception or error thrown inside the check is
  caught. The original value is returned, an "internal error" is counted
  and logged. A bug in this plugin must never stop an import run.
- **E10. Never returns false.** The callback returns either the value it
  received or `true`. It never turns `true` into anything else and never
  returns `false`.
- **E11. A later callback.** A callback at a higher priority can still
  change the result. That is outside this plugin's control and is
  documented.
- **E12. Echo absent.** Without Echo the filter is never applied; the
  plugin does nothing on its own, and the admin page says that no Echo
  calls have been seen yet.
- **E13. Skip list.** Hosts on a skip list (empty by default, set by a
  filter or the settings page) are not checked: the value passes through
  and "not checked" is counted. The README names aggregator redirect
  hosts (for example Google News article links) as the usual entry,
  because their robots.txt speaks for the aggregator, not the publisher.

### 4.7 Multisite and must-use

- **S1. Per-site settings.** Mode, token, failure policy, TTLs, skip list
  and bot page URL are per site.
- **S2. Network-wide cache.** robots.txt entries are shared across the
  network (a host's file does not depend on which site asks), stored in
  network-wide transients or a network table. The token is applied at
  check time (G8), so sites with different tokens share entries safely.
- **S3. Per-site counters and log.** Each site keeps its own counters and
  blocked log.
- **S4. Network activation.** All sites get defaults without visiting an
  admin page. A site added later gets defaults on first use.
- **S5. Must-use.** As a must-use plugin there is no activation hook;
  defaults are applied lazily on first read. Settings can be fixed in code
  through filters or constants so an operator can run it with no admin
  page visit at all.
- **S6. Uninstall on multisite** removes the plugin's data from every
  site and the network cache (section 6).

### 4.8 Failure policy (fail closed or fail open)

- **F1. Fail closed (default).** When robots.txt is unreachable and no
  stale copy exists, every URL on that origin counts as disallowed. This
  follows RFC 9309 2.3.1.4 (MUST assume complete disallow) and is the only
  setting under which a /bot page can claim full RFC 9309 compliance.
- **F2. Fail open.** The operator may choose to treat unreachable as
  allowed. The admin page and the statement (4.10) then say so plainly:
  the importer is not RFC 9309 compliant for unreachable files. This
  exists for operators whose sources have flaky robots.txt servers and who
  prefer to keep importing; it is a conscious trade.
- **F3. Unreachable outcomes are cached** for the error TTL (C4) under
  both policies, so a dead host is not refetched on every item.
- **F4. Internal errors** (E9) always pass the value through, under both
  policies. Failing closed on our own bug would silently stop imports; the
  counter and log make the bug visible instead.
- **F5. Long outage.** RFC 9309 2.3.1.4 allows treating a file that has
  been unreachable for a long time (for example 30 days) as unavailable.
  The plugin does not do this automatically; the admin page lists origins
  that have been unreachable for more than 7 days so the operator can
  decide.

### 4.9 Feed URLs (documented limit)

- **L1.** Echo applies no filter before it fetches the feed itself, so the
  feed URL is never checked. The README and the statement (4.10) say so.
- **L2.** The README recommends the operator-side policy: before adding a
  feed, check the feed URL with `wp echo-robots-guard check <feed URL>`;
  review existing feeds the same way on a schedule; remove or pause any
  feed whose URL is disallowed for your token; publish an email address on
  the /bot page so a publisher can ask for a feed to be removed.
- **L3.** The CLI accepts a list of URLs (one per line on standard input)
  so an operator can audit every feed in one run.

### 4.10 Truthfulness for the operator's /bot page

- **T1.** The plugin provides a statement, available as a shortcode and as
  a function, that describes what the plugin does in the mode it is in now:
  the product token; the robots.txt fetch user agent; that the check
  follows RFC 9309 (group for the token, else `*`, longest match, Allow on
  a tie); how 4xx and unreachable files are treated under the current
  failure policy; the cache TTLs; that feed fetches are not covered; how to
  opt out (`User-agent: <token>` plus `Disallow: /`).
- **T2. Enforce.** The statement says pages disallowed for the token are
  not downloaded.
- **T3. Shadow.** The statement says disallowed pages are recorded, not
  yet skipped. It never says "we honour robots.txt" in shadow mode.
- **T4. Off.** The statement says the check is switched off.
- **T5. Fail open.** The statement says unreachable robots.txt files are
  treated as allowing access.
- **T6. Skip list not empty.** The statement says some hosts are not
  checked, without naming them unless the operator opts in.
- **T7.** All statement text passes through translation functions and a
  filter, so an operator can reword it; the filter receives the mode and
  settings so a reworded text can stay truthful.
- **T8. The /bot path.** The README warns that WordPress may answer an
  unknown `/bot` with a 301 to an unrelated post whose slug starts with
  "bot" (its 404 permalink guess). The operator should publish a real page
  at that path; the plugin does not create pages.

### 4.11 General API (without Echo)

- **A1.** A function answers "may I fetch this URL?" for any caller. It
  returns a decision: allowed, disallowed or not checked, with the reason
  (matched rule, unavailable, unreachable under policy, stale copy, skip
  list, not an http(s) URL, internal error) and the origin's cache state.
- **A2.** The function answers the robots.txt truth in every mode. The
  mode only governs what the Echo callback does with the answer.
- **A3.** A filter offers the same answer to code that prefers not to call
  a plugin function directly: a caller applies it with a default of `null`
  and a URL, and gets `true`, `false`, or `null` when the plugin is not
  active or the URL is not checked.
- **A4.** An optional token argument lets a caller check against another
  token (for testing or for a second importer with its own identity).
- **A5.** Counters for API calls are kept apart from Echo counters, so the
  admin page can tell them apart.

### 4.12 Admin page and WP-CLI

- **U1.** A page under Tools shows mode, token, failure policy, TTLs,
  today's and all-time counters, the last 100 blocked or would-block URLs
  with their matching rule, origins held in cache with their outcome, and
  origins unreachable for more than 7 days.
- **U2.** Settings are saved through a form with a nonce and a
  `manage_options` check (network admin and `manage_network_options` for
  network-wide items).
- **U3.** A "clear cache" button (whole cache or one origin) uses a nonce
  and the same capability.
- **U4.** WP-CLI: `check <url>...` prints allowed, disallowed or not
  checked, with the matching rule and cache state; `--token`, `--fresh`
  (ignore cache), and `--format` (table, json, csv) options; exit status 0
  when all URLs are allowed or not checked, 1 when any is disallowed.
- **U5.** WP-CLI: `cache clear [<origin>]`, `cache list`, `stats [--reset]`,
  `log [--limit]`, `status`.
- **U6.** `check` reads URLs from standard input when given `-` (L3).

## 5. Public interface

Names below are the public contract. PHP namespace
`IndianOpinion\EchoRobotsGuard`. Hook names use the prefix
`echo_robots_guard_`.

### Functions and classes

| Name | Purpose |
|---|---|
| `may_fetch( url, token = null )` (namespaced function) | Returns a `Decision` (A1 to A4). |
| `statement( )` (namespaced function) | Returns the HTML statement for the /bot page (T1 to T7). |
| `Decision` (value object) | Read-only: `allowed` (bool or null when not checked), `reason` (one of a fixed set of strings), `rule` (agent, directive and pattern that matched, or null), `origin`, `cache` (hit, miss, stale). |
| `Parser` | Parses robots.txt text into groups. Pure, no WordPress. |
| `Matcher` | Selects the group for a token and decides a path. Pure, no WordPress. |
| `Fetcher` | Fetches robots.txt through the WordPress HTTP API. |
| `Cache` | Stores and reads origin entries. |
| `RobotsGuardException` and subclasses | Typed errors inside the plugin; always caught at the hook boundary (E9). |

The parser and matcher must be usable without WordPress, so they can be
tested alone and reused.

### Hooks

| Hook | Type | Default and meaning |
|---|---|---|
| `echo_filter_dup_check` | filter (Echo's) | Our callback at priority 20. |
| `echo_robots_guard_priority` | filter | 20. Read once at load. |
| `echo_robots_guard_may_fetch` | filter | Default `null`; args URL, optional token. Returns true, false or null (A3). |
| `echo_robots_guard_mode` | filter | Saved mode: `enforce`, `shadow` or `off`. |
| `echo_robots_guard_token` | filter | Saved token. |
| `echo_robots_guard_fail_policy` | filter | `closed` or `open`. |
| `echo_robots_guard_ttl` | filter | 86400 seconds. |
| `echo_robots_guard_error_ttl` | filter | 3600 seconds. |
| `echo_robots_guard_stale_window` | filter | 2592000 seconds (30 days). |
| `echo_robots_guard_timeout` | filter | 8 seconds. |
| `echo_robots_guard_max_bytes` | filter | 512000. Values below 512000 are raised to 512000. |
| `echo_robots_guard_max_rules` | filter | 10000. |
| `echo_robots_guard_skip_hosts` | filter | Empty list. |
| `echo_robots_guard_allowed_internal_hosts` | filter | Empty list (H14). |
| `echo_robots_guard_user_agent` | filter | `<token>/<version> (+<bot page URL>)`. |
| `echo_robots_guard_bot_page_url` | filter | Home URL plus `bot`. |
| `echo_robots_guard_statement` | filter | The statement HTML, with mode and settings as args. |
| `echo_robots_guard_counters` | filter | Read-only view of counters for other code. |
| `echo_robots_guard_decision` | action | Fires after every check with URL, decision and context (echo or api). |
| `echo_robots_guard_blocked` | action | Fires on every block or would-block with URL, rule and mode. |
| `echo_robots_guard_fetched` | action | Fires after each robots.txt fetch with origin, outcome, status and bytes. |

### Shortcode

`[echo_robots_guard_statement]` prints `statement()`.

### WP-CLI

`wp echo-robots-guard check|cache|stats|log|status` as in U4 to U6.

### Constants (optional, for must-use installs)

A constant per setting may fix its value in code (for example the mode or
the token). When a constant is defined, the admin page shows that setting
as read-only.

### Options

One settings option per site (array, autoloaded, small). One counters
option per site and one log option per site, both not autoloaded. Cache
entries as in section 6.

## 6. Data

| What | Where | Retention | Uninstall |
|---|---|---|---|
| Settings | one option per site | until uninstall | deleted |
| Counters (per UTC day for the last 30 days, plus all-time) | one non-autoloaded option per site | 30 days of daily rows | deleted |
| Blocked log (last 100 entries: time, URL, origin, rule, mode, context) | one non-autoloaded option per site | ring buffer | deleted |
| robots.txt cache entries | network-wide transients, or one plugin table if the builder chooses it (section 13) | TTL, plus the stale copy for the stale window | deleted, including every entry |
| Index of cached origins (needed to list and clear entries when transients are used) | one non-autoloaded network option | follows the entries | deleted |

No personal data is stored. URLs and robots.txt rules are public. The log
holds article URLs only. Nothing leaves the site except the robots.txt
fetch.

Uninstall (`uninstall.php`) removes all of the above, on every site of a
network. Deactivation removes nothing.

## 7. Configuration and sensible defaults

| Setting | Default | Range or values | Why |
|---|---|---|---|
| Mode | `shadow` on first install | `enforce`, `shadow`, `off` | Review what would be skipped before skipping. The admin page shows a notice until the operator picks a mode. |
| Product token | Derived from the site title: letters, `_` and `-` only, CamelCase, ending in `Bot`; fallback `WordPressImporterBot` | 1 to 64 of `A-Za-z_-` (RFC 9309 2.2.1) | Publishers need a stable name to write rules for. The operator should set it and keep it. |
| Failure policy | `closed` | `closed`, `open` | RFC 9309 2.3.1.4. |
| TTL after 2xx or 4xx | 24 hours | 5 minutes to 7 days | RFC 9309 2.4. |
| TTL after unreachable | 1 hour | 1 minute to the TTL above | A blip should not block a host for a day. |
| Stale window | 30 days | 0 (off) to 90 days | RFC 9309 2.4 and 2.3.1.4. |
| Fetch timeout | 8 seconds | 1 to 30 | Bound import run time. |
| Max bytes | 512,000 | at least 512,000 | RFC 9309 2.5. |
| Bot page URL | home URL plus `bot` | any http(s) URL | Named in the fetch user agent. |
| Skip hosts | empty | host names | E13. |
| Priority | 20 | integer, filter only | E1. |

An invalid saved value falls back to the default and the admin page shows
which setting was rejected.

## 8. Security and privacy

- robots.txt is untrusted input. It is never evaluated, never used to build
  an unescaped regular expression, and bounded in size (P15) and rule count
  (P18).
- Matching is linear in time (M15): a hostile file cannot stall the import.
- Server-side request forgery: item URLs come from third-party feeds, so the
  robots.txt fetch uses the WordPress safe HTTP rules (H14) and only http
  and https (H7, H15).
- TLS certificates are verified. No cookies, credentials or referrer are
  sent (H13).
- Admin actions use nonces and capability checks (U2, U3). All admin output
  is escaped. The WP-CLI commands run with the privileges of the shell.
- The log stores public article URLs only. No reader data is touched.
- The statement is the operator's public claim. It is generated from the
  live settings so it cannot drift from what the code does (T1 to T7).
- The plugin never writes outside its own options, transients or table.

## 9. Performance budget

- Cache hit: under 1 ms per check on a typical file (under 100 rules),
  measured in the unit tests on the CI runner.
- Parse of a 500 KiB file: under 50 ms. Match of one path against 10,000
  rules: under 20 ms.
- At most one network fetch per origin per TTL, and per PHP request (H16).
- Worst case added time per import run: number of new origins times the
  timeout. The README states this and suggests a shorter timeout for runs
  with many new hosts.
- No autoloaded data beyond the small settings option. Counters are written
  once per check; the builder may batch them per request (section 13).

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. WordPress 6.4 or later.
- Single site, multisite (per-site or network activation), must-use.
- Persistent object caches (Redis, Memcached) and none.
- Any Echo version that applies `echo_filter_dup_check` to the item URL
  with the documented meaning of `true`. The plugin makes no other
  assumption about Echo.
- Works beside a duplicate-check plugin on the same filter (E1, E2).
- Hosts that block outbound HTTP: every fetch is unreachable; the admin
  page shows the fetch error count so the operator sees why.
- Page caches do not matter: the plugin runs in cron and CLI contexts and
  the statement shortcode reads settings only, so a cached /bot page shows
  the statement as of the cache time. The README says to purge the /bot
  page after changing the mode.

## 11. Observability

- **Counters**, per site, per UTC day and all-time: checked, allowed,
  blocked, would block (shadow), not checked, passed through (E2), cache
  hits, cache misses, stale copies used, fetches, fetch errors (by class:
  timeout, DNS, TLS, refused, 5xx, 429, redirect limit, unsafe address),
  rules dropped (P18), internal errors. Kept apart for Echo and API
  callers (A5). Exposed on the admin page, through
  `echo_robots_guard_counters`, and by `wp echo-robots-guard stats`.
- **Blocked log:** every block and would-block with time, URL, origin,
  the agent of the group used, the directive and the pattern that matched,
  or "unreachable, fail closed". Last 100 kept (section 6). Also passed to
  the `echo_robots_guard_blocked` action so an operator can send it to
  their own log.
- **PHP error log:** one line per internal error (E9), and one line per
  origin per day for fetch errors, prefixed with the plugin name. Never one
  line per item.
- **Echo's own log:** not written. The plugin does not call Echo functions.
- **Admin notices:** mode not chosen yet; TTL above 24 hours; fail open in
  use; no Echo calls seen in 7 days while the mode is not off.

## 12. Acceptance tests

PHPUnit 10. Parser and matcher tests use no WordPress. Integration tests
use the stub layer in `tests/stubs/` for options, transients, the HTTP API
and the filter API. Every robots.txt fixture lives in
`tests/fixtures/robots/` as a plain file; HTTP fixtures are described in
`tests/fixtures/http/` (status, headers, body file). Unless stated, the
token is `ExampleBot`.

### Parsing and group selection

| Test | Fixture | Expectation | Cases |
|---|---|---|---|
| T01 | `line-endings-mixed.txt` (LF, CR, CRLF) | three groups found | P1 |
| T02 | `bom.txt` | BOM ignored; first line is a User-agent | P2 |
| T03 | `comments.txt` (full-line and trailing `#`) | comment text never becomes a rule | P3 |
| T04 | `whitespace.txt` (tabs and spaces around keys, colons, values) | rules read correctly | P4 |
| T05 | `key-case.txt` (`USER-AGENT`, `disALLOW`) | treated as known keys | P5 |
| T06 | `no-colon.txt` | lines ignored; group intact | P6 |
| T07 | `unknown-keys.txt` (Sitemap, Crawl-delay, Host between rules and between two User-agent lines) | groups unchanged by them | P7, non-goals 3 and 4 |
| T08 | `grouping.txt` (two consecutive agents, then rules, then a new agent) | first two share rules; third is a new group | P8, G7 |
| T09 | `rules-before-agent.txt` | leading Disallow ignored | P9 |
| T10 | `empty-group.txt` (`User-agent: ExampleBot` with no rules, then `*` with `Disallow: /`) | `/x` allowed | P10, G6 |
| T11 | `empty-disallow.txt` | `/x` allowed | P11 |
| T12 | `empty-allow.txt` | rule list empty | P12 |
| T13 | `no-leading-slash.txt` (`Disallow: private`, `Disallow: *.pdf`) | `/private/a` blocked; `/a.pdf` blocked | P13 |
| T14 | `agent-with-version.txt` (`User-agent: ExampleBot/2.1 (+https://x.example)`) | group applies to ExampleBot | P14 |
| T15 | generated 600 KiB file with a Disallow at 400 KiB and another at 550 KiB, the 500 KiB boundary inside a line | first rule kept, second ignored, cut line discarded | P15, C12 |
| T16 | `invalid-utf8.txt` | parse completes; valid rules applied | P16, P17 |
| T17 | `malformed-mix.txt` (garbage lines between good ones) | every good rule kept | P17 |
| T18 | generated file with 10,050 rules | 10,000 kept; drop counted once | P18, C12 |
| T19 | `html-page.txt` (an HTML home page) | no groups; all allowed | P19, G5 |
| T20 | `token-case.txt` (`User-agent: examplebot`) | applies to ExampleBot | G1 |
| T21 | `token-prefix.txt` (groups for `Example` and `ExampleBotNews`, both `Disallow: /`) | `/x` allowed for ExampleBot | G2 |
| T22 | `merge.txt` (two separate ExampleBot groups) | rules of both apply | G3 |
| T23 | `star-only.txt` (two `*` groups) | merged rules apply | G4 |
| T24 | empty file | everything allowed | G5 |
| T25 | `star-and-named.txt`, checked with tokens ExampleBot and OtherBot from one cache entry | each gets its own group; no refetch | G8, C1 |

### Matching

| Test | Fixture or rules | Expectation | Cases |
|---|---|---|---|
| T26 | `Disallow: /a` | `/a`, `/abc`, `/a/b` blocked; `/b` allowed; `/?x` path built from query | M1, M2 |
| T27 | URL with fragment and empty path | fragment ignored; empty path is `/` | M1 |
| T28 | `Allow: /p` and `Disallow: /p/x` | `/p/x/1` blocked; `/p/y` allowed | M3 |
| T29 | `Disallow: /folder/` and `Allow: /folder/page` | `/folder/page` allowed | M3 |
| T30 | `Allow: /page` and `Disallow: /*page` (equal length) | allowed | M4 |
| T31 | rules that match nothing | allowed | M5 |
| T32 | `Disallow: /*.php`, `Disallow: /a**b` | `/x/y.php` blocked; `/a/zzz/b` blocked | M6 |
| T33 | `Disallow: /*.php$`, `Disallow: /a$b` | `/x.php` blocked, `/x.php?y` allowed; `$` in the middle is literal | M7 |
| T34 | `Disallow: /` plus `Allow: /public` | `/` blocked; `/public/1` allowed | M8 |
| T35 | `Disallow: /` | `/robots.txt` allowed | M9 |
| T36 | `Disallow: /caf%C3%A9`, `Disallow: /%7Euser` | `/café`, `/caf%c3%a9` blocked; `/~user/x` blocked | M10 |
| T37 | `Disallow: /a/b` | `/a%2Fb` allowed | M11 |
| T38 | `Disallow: /x%2A`, `Disallow: /price%24` | `/x*` path blocked; `/x` allowed; literal `$` matched | M12 |
| T39 | `Disallow: /*?utm_` | `/story?utm_source=x` blocked; `/story` allowed | M13 |
| T40 | `Disallow: /Private` | `/private` allowed | M14 |
| T41 | 10,000 rules of the form `/*a*a*a*a*b`, 2,000-byte path of `a` | decision within budget | M15, section 9 |

### HTTP

| Test | HTTP fixture | Expectation | Cases |
|---|---|---|---|
| T42 | item on `https://a.example`, then on `http://a.example`, then `www.a.example` | three fetches, three entries | H1 |
| T43 | `https://A.Example:443/x` then `https://a.example/y` | one fetch | H2, H3 |
| T44 | URL with userinfo and IPv6 literal host | userinfo not sent; host kept in brackets | H3 |
| T45 | 200 with rules | rules applied | H4 |
| T46 | 200 with empty body | allowed | H4 |
| T47 | three redirects to another host, then 200 with `Disallow: /` | original origin blocked; the other host not cached under the original key | H5 |
| T48 | six redirects | allowed (unavailable) | H6 |
| T49 | redirect to `ftp://` | unreachable | H7 |
| T50 | 401, 403, 404, 410 (data provider) | allowed | H8 |
| T51 | 429 | unreachable | H9 |
| T52 | 500, 503, timeout, DNS error, TLS error, status 999 (data provider) | unreachable | H10 |
| T53 | 200 cached, expired, then 503 | stale copy used; stale counter up | H11, C7 |
| T54 | stale copy older than the window, then 503 | failure policy applies | H11 |
| T55 | timeout setting 3 | HTTP stub receives 3 | H12 |
| T56 | any fetch | user agent is `ExampleBot/<version> (+<bot page URL>)`; no cookie header | H13 |
| T57 | item on `http://127.0.0.1/`, `http://169.254.169.254/`, `http://10.0.0.5/` | unreachable, no request made; allowed-internal filter lets one through | H14 |
| T58 | `ftp://x`, `mailto:a@b`, `/relative`, empty string | not checked; value unchanged | H15, E3 |
| T59 | cache writes fail (stub), five items on one origin | one fetch | H16, C10 |

### Cache

| Test | Setup | Expectation | Cases |
|---|---|---|---|
| T60 | fetch, then inspect the entry | groups, outcome, status, fetch time, bytes stored; raw body not stored | C2 |
| T61 | 200 and 404 | expiry 24 hours | C3 |
| T62 | TTL set to 2 days | accepted; admin warning present | C3 |
| T63 | 503 | expiry 1 hour; error TTL above success TTL is clamped | C4 |
| T64 | two checks on one origin | second is a hit, no fetch | C5 |
| T65 | clock moved past expiry | refetch; new entry | C6 |
| T66 | clear all; clear one origin (admin and CLI) | the right entries gone; next check refetches | C8, U3, U5 |
| T67 | with and without the object cache stub | same answers | C9 |
| T68 | change mode, token, policy without clearing | next check uses new settings and the existing entry | C11 |

### Echo integration and priority

| Test | Setup | Expectation | Cases |
|---|---|---|---|
| T69 | callback registered | on `echo_filter_dup_check` at 20; priority filter changes it | E1 |
| T70 | a stub duplicate callback at 10 returns `true` | our callback returns `true`, no fetch, "passed through" counted | E2, E10 |
| T71 | value is a title string, an integer, an array, `false`, `null` | each returned identical, not counted as checked | E3 |
| T72 | URL with `&amp;` in the query, disallowed by `Disallow: /*?a=1&b=2` | blocked; on allow, the returned value is the original encoded string | E4 |
| T73 | allowed URL | original value returned | E5 |
| T74 | disallowed URL, enforce | `true`; blocked counter and log entry with the rule | E6 |
| T75 | disallowed URL, shadow | original value; would-block counter and log entry | E7 |
| T76 | mode off | original value; no fetch; no counter change; `may_fetch` still answers | E8, A2 |
| T77 | parser stub throws | original value; internal error counted and logged | E9, F4 |
| T78 | a stub callback at 30 returns `false` after ours returned `true` | final value `false` (documented, not prevented) | E11 |
| T79 | plugin loaded, filter never applied | admin page shows no Echo calls seen | E12 |
| T80 | host on the skip list | value unchanged; not-checked counted | E13 |

### Multisite and must-use

| Test | Setup | Expectation | Cases |
|---|---|---|---|
| T81 | two sites with different modes and tokens | each uses its own settings | S1 |
| T82 | two sites check the same origin | one fetch; shared entry; tokens applied separately | S2, G8 |
| T83 | block on site 1 | counter and log on site 1 only | S3 |
| T84 | network activation; a site created later | defaults present on first use | S4 |
| T85 | loaded as must-use with constants for mode and token | constants win; admin shows them read-only | S5, section 5 constants |
| T86 | uninstall on a two-site network | settings, counters, logs on both sites and every cache entry and the index removed | S6, section 6 |

### Failure policy

| Test | Setup | Expectation | Cases |
|---|---|---|---|
| T87 | 503, no stale copy, policy closed | disallowed; log rule "unreachable, fail closed" | F1 |
| T88 | same, policy open | allowed; admin notice present | F2 |
| T89 | 503 twice within an hour under each policy | one fetch | F3 |
| T90 | origin unreachable for 8 days (clock stub) | listed on the admin page; still fails per policy | F5 |

### Feed limit, statement, API, admin, CLI

| Test | Setup | Expectation | Cases |
|---|---|---|---|
| T91 | README check | README states the feed limit and the operator policy | L1, L2 |
| T92 | `check -` with three URLs on standard input | three results; exit status 1 if any disallowed | L3, U4, U6 |
| T93 | statement in enforce, closed | contains token, fetch user agent, RFC 9309 rules, 4xx and unreachable handling, TTLs, feed limit, opt-out lines | T1, T2 |
| T94 | statement in shadow | says recorded, not skipped; does not contain the "not downloaded" sentence | T3 |
| T95 | statement in off | says the check is off | T4 |
| T96 | statement under fail open; with a skip list | says unreachable means allowed; says some hosts are not checked | T5, T6 |
| T97 | statement filter | receives mode and settings; output replaced | T7 |
| T98 | README check | README warns about the `/bot` 404 guess | T8 |
| T99 | `may_fetch` on blocked, allowed, unavailable, unreachable, stale, skip list, non-URL, internal error | each returns the matching `allowed` and `reason`, and the rule when one matched | A1 |
| T100 | `apply_filters('echo_robots_guard_may_fetch', null, url)`; plugin inactive | true or false; `null` when inactive or not checked | A3 |
| T101 | `may_fetch` with token OtherBot | OtherBot group used | A4 |
| T102 | one Echo check and one API check | separate counters | A5 |
| T103 | admin page render as admin and as editor | admin sees counters, log, cache list; editor refused | U1, U2 |
| T104 | settings save without nonce; with bad values | refused; bad values fall back with a notice | U2, section 7 |
| T105 | `cache list`, `stats --reset`, `log --limit=5`, `status`, `check --format=json --fresh --token=OtherBot` | expected output; `--fresh` bypasses cache | U4, U5 |
| T106 | counters output of `echo_robots_guard_counters` and actions `decision`, `blocked`, `fetched` | fire with documented arguments | section 11 |
| T107 | cache hit and 500 KiB parse timings | within section 9 budget on CI | section 9 |
| T108 | grep of the source tree | no `eval`, no shell calls, no `@` suppression | section 8 |

Every case in section 4 appears in the Cases column at least once.

## 13. Open questions

To be answered by the builder in `docs/ARCHITECTURE.md`.

1. Cache store: network-wide transients plus an origin index, or one small
   plugin table. A table makes listing, clearing and uninstall exact; a
   transient keeps the plugin table-free. Pick one and justify it.
2. Default product token: the rule in section 7 (derived from the site
   title) or a fixed generic fallback with a mandatory notice until the
   operator sets one.
3. Default mode on a fresh install: `shadow` (as specified, safer) or
   `enforce` (makes the statement true from day one).
4. 429 as unreachable (H9): keep, or follow the RFC text strictly and treat
   it as unavailable.
5. Counter writes: one option write per check, or one batched write at
   shutdown per request.
6. Whether to offer an opt-in hard cap on robots.txt fetches per request,
   and what an item past the cap gets (failure policy or pass-through).
7. Leniency for common misspellings of keys (for example `Dissallow`):
   accept or ignore. RFC 9309 does not ask for it.

## 14. Lessons from production

Each lesson is written as a rule the build must follow.

1. Run after the duplicate check, and never turn its `true` into anything
   else. Two plugins share one filter; the order and the pass-through are
   the contract.
2. Treat the filter value as untrusted in type. On one path it is a title,
   not a URL. Anything that is not an http or https URL goes back
   untouched.
3. Decode HTML entities before parsing the URL. Feed URLs carry `&amp;`,
   and a query rule will miss without decoding. Return the original value.
4. Match the product token exactly, not as a prefix. Prefix matching lets a
   group written for a short, unrelated name catch your bot.
5. Ship shadow mode first. Log what would be skipped, read the log with an
   editor, then switch to enforce.
6. The public statement must follow the code. Generate it from the live
   mode; never claim enforcement while the check is in shadow or off.
7. Give `/bot` a real page. Without one, WordPress may redirect it with a
   301 to whichever post's slug starts with "bot".
8. Say what is not covered. The feed fetch is outside reach; the statement
   must say so and give an email address for opt-outs.
9. Cache errors for less time than successes. A one-hour error TTL lets a
   blip pass; a day-long one blocks a host for a day.
10. Keep counters and logs out of autoload, and never log one line per item
    to the PHP log. An import run can check thousands of items.
11. Catch everything at the hook boundary. A fault in the guard must not
    stop the import; count it and show it instead.
12. Do not check the robots.txt of an aggregator's redirect host as if it
    were the publisher's. Pass those URLs through via the skip list and
    say so.
13. The fetch user agent and the importer's own requests are different. The
    plugin can only name itself on its own fetch, so key every decision on
    the product token and say this on the /bot page.
14. Store all parsed groups, not the group chosen for today's token, or
    key the cache on the token. Otherwise a token change silently reuses
    rules chosen for the old name.
