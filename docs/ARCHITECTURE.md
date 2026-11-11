# Architecture

This document describes how echo-robots-guard 0.1.0 is built: the modules,
the data flow, the storage, and every decision taken on the open questions
in [PRD.md](PRD.md) section 13, plus the places where the build reads the
PRD in a particular way.

## Modules

```text
echo-robots-guard.php        plugin header, constants, activation hooks, boot
uninstall.php                calls Uninstaller::run()
mu-loader/                   one-line loader for wp-content/mu-plugins/
src/
  autoload.php               PSR-4 autoloader for installs without Composer
  functions.php              may_fetch(), statement()

  Robots/                    pure PHP, no WordPress
    Parser.php               robots.txt text -> RobotsFile (RFC 9309 2.1 to 2.5)
    RobotsFile.php           groups + parse report; array form for caching
    Group.php, Rule.php      one group; one Allow or Disallow rule and its matcher
    Matcher.php              group selection for a token, longest match
    MatchResult.php          allowed, rule, agent
    Normalizer.php           percent-encoding normalisation for patterns and paths
    Target.php               URL -> origin (scheme, host, port) + normalised path

  Plugin.php                 wiring and hook registration
  Settings.php               per-site settings: constants, option, filters, validation
  Guard.php                  check(url, token, context, fresh) -> Decision
  Decision.php               read-only answer with reason, rule, origin, cache state
  Fetcher.php, FetchResult.php   robots.txt over the WordPress HTTP API
  Cache.php                  object cache in front of the store
  CacheEntry.php             one origin's state
  Store/EntryStore.php       storage interface
  Store/TableStore.php       the network-wide table
  Recorder.php               counters and the blocked log, buffered per request
  EchoIntegration.php        the echo_filter_dup_check callback
  Statement.php              the /bot page statement and shortcode
  Admin/AdminPage.php        Tools > Echo Robots Guard
  Cli/Command.php            wp echo-robots-guard ...
  Uninstaller.php            removes everything on every site
  Logger.php, Clock.php      PHP error log sink; time source (replaced in tests)
  Exception/                 RobotsGuardException and subclasses
```

The `Robots` namespace has no WordPress calls at all and can be copied into
another project. `examples/check-robots-standalone.php` runs it with plain
PHP.

## Data flow

```text
Echo applies echo_filter_dup_check(value)
  EchoIntegration::filter (priority 20, read once at load)
    mode off                        -> return value
    value === true                  -> count passed_through, return true
    not a string / not http(s)      -> return value (not counted)
    html_entity_decode + trim
    Guard::check(url, token, 'echo')
      Target::fromUrl               -> not_http_url (not checked)
      Settings::isSkipped(host)     -> skip_list (not checked)
      per-request memo hit          -> hit
      Cache::get(origin)            -> object cache, then table
        fresh entry                 -> hit
        missing or expired:
          fetch cap reached         -> stale copy, else failure policy (fetch_cap)
          Fetcher::fetch            -> ok | unavailable | unreachable
          Parser::parse (on ok)     -> RobotsFile
          Cache::put                -> table + object cache (failure counted, answer unchanged)
      ok          -> Matcher::decide(file, token, path)
      unavailable -> allowed
      unreachable -> stale copy within window, else failure policy
    allowed                         -> return value
    disallowed, enforce             -> count blocked, log, action, return true
    disallowed, shadow              -> count would_block, log, action, return value
  any Throwable                     -> count internal_errors, log once, return value
shutdown -> Recorder::flush (one write per option per site)
```

The public API (`may_fetch()`, the `echo_robots_guard_may_fetch` filter and
WP-CLI `check`) calls the same `Guard::check` with context `api`.

## Storage

| What | Where | Autoload | Removed by |
|---|---|---|---|
| Settings | option `echo_robots_guard_settings`, per site | yes (small) | uninstall |
| Counters | option `echo_robots_guard_counters`, per site: 30 UTC days plus all-time, Echo and API apart | no | uninstall, `stats --reset` |
| Blocked log | option `echo_robots_guard_log`, per site: last 100 | no | uninstall, `stats --reset` |
| robots.txt cache | table `{base_prefix}echo_robots_guard_cache`, network-wide; object cache group `echo_robots_guard` (global) in front | n/a | uninstall (table dropped), daily purge of dead rows |
| Schema version | network option `echo_robots_guard_db_version` | n/a | uninstall |
| Purge schedule | cron event `echo_robots_guard_purge`, daily | n/a | deactivation, uninstall |

Cache table columns:

| Column | Meaning |
|---|---|
| `origin_hash` | SHA-1 of the origin; primary key (keeps the index short on any MySQL) |
| `origin` | `scheme://host[:port]`. The "host" of decision 1 is the full origin, because RFC 9309 scopes a robots.txt to scheme, host and port (H1) |
| `outcome` | `ok`, `unavailable` or `unreachable` |
| `status` | HTTP status of the last fetch, or null for a network error |
| `error_class` | `timeout`, `dns`, `tls`, `refused`, `network`, `5xx`, `429`, `status`, `redirect`, `redirect_limit`, `redirect_scheme`, `unsafe`, or null |
| `body` | the last 2xx file **in parsed form** (JSON of every group and rule), never the raw text (C2) |
| `bytes` | bytes read from the body |
| `fetched_at`, `expires_at` | last fetch, and when it stops being fresh |
| `body_fetched_at` | when the 2xx copy in `body` was fetched; drives the stale window |
| `unreachable_since` | start of the current run of unreachable fetches; drives the 7-day list (F5) |

When the latest outcome is `unreachable`, `body` keeps the previous 2xx copy
so it can stand in (H11, C7). A fresh 4xx clears it.

## Decisions on the open questions (PRD section 13)

### 1. Cache store: the plugin's own table, with the object cache in front

One network-wide table, as above, read through the object cache. A
persistent object cache (Redis, Memcached) answers most reads; without one,
the table answers them. Why a table rather than transients:

- Listing, clearing one origin, clearing all, the 7-day unreachable list and
  uninstall are exact queries. With transients they need a separate index
  option that can drift from the entries and grows with every origin.
- Transients on a site with a persistent object cache never reach the
  database, so "list everything" would be impossible there.
- Stale copies must outlive their TTL for up to 90 days. A transient that
  expires takes its stale copy with it.

Costs: a `CREATE TABLE` on activation (and on first use for must-use
installs, guarded by the schema version option), and expired rows that do
not vanish on their own. A daily cron event deletes rows that are expired
and hold no 2xx copy younger than the longest allowed stale window. The
object cache entry lives until the row's expiry; after that a read goes to
the table, which still holds the stale copy.

### 2. Default product token: from the site title, confirmed by the operator

The default is the site title, letters only, each word capitalised and
joined, ending in `Bot`: "Daily Example" becomes `DailyExampleBot`. A title
with no letters gives `WordPressImporterBot`. The token is saved on first
read, so a later change of site title does not silently change the public
identity. Until the operator saves the settings page, the admin page shows
the token and asks them to confirm or change it, and a notice on other
admin screens points there until they do.

### 3. Default mode on a fresh install: enforce

This departs from PRD section 7, which specified shadow. Enforce makes the
statement on the /bot page true from the first import, and a fresh install
has no history to protect. Shadow mode stays fully available and is
documented (README quick start, the admin page notice and the mode label)
as the trial setting for a site that already imports. The PRD's "mode not
chosen yet" notice became the "confirm the token" notice, which also
suggests shadow mode for existing sites.

### 4. HTTP 429 is unreachable

As the PRD says (H9). RFC 9309 2.3.1.3 groups every 4xx as "unavailable,
allow all". A 429 means the server is busy, not that there is no file, and
reading it as "allow all" would reward a publisher's overload with more
crawling. Under fail closed a 429 therefore blocks the origin for the error
TTL (one hour by default) unless a stale copy is held. This departure is
stated in the README, in the admin wording and in the public statement.

### 5. Counters: buffered per request, written once at shutdown

`Recorder` keeps counts and log entries in memory, keyed by site, and
writes them in `shutdown`: one `update_option` for counters and one for the
log per site touched. On multisite it switches to each site to write. An
import of thousands of items costs two writes. The trade: two requests
flushing at the same moment can lose increments (read-modify-write), and a
fatal error before shutdown loses that request's counts. Decisions never
depend on counters, so this is acceptable. The admin page and WP-CLI read
the stored values merged with the unflushed buffer, so a CLI run sees its
own counts.

### 6. robots.txt fetches capped at 20 per request

Configurable from 1 to 10000 by setting, constant
`ECHO_ROBOTS_GUARD_MAX_FETCHES` or filter
`echo_robots_guard_max_fetches_per_request`. Cache hits never count. An
item whose origin would need a fetch past the cap:

1. uses a 2xx copy within the stale window if one is held (reason
   `stale_copy`);
2. otherwise gets the failure policy (reason `fetch_cap`): skipped under
   fail closed, allowed under fail open.

Nothing is cached for it, so the next request fetches that origin normally.
The cap bounds the worst-case added time per request at
`cap x timeout` (160 s with the defaults). Counted as `fetch_cap_reached`.
WP-CLI `check` lifts the cap for its own run, because the operator asked
for every URL listed there (L3).

### 7. Common misspellings of Disallow and Allow are accepted and logged

PRD section 13 names `Dissallow` as the example. The accepted list is in
`Parser::MISSPELLINGS`: `dissallow`, `dissalow`, `disalow`, `diasllow`,
`disallaw`, `disallwo` for Disallow, and `alow`, `allwo`, `alllow` for
Allow. Keys are compared case-insensitively first. Each robots.txt fetch
that needed one adds to the `misspellings_accepted` counter and writes one
line to the PHP error log naming the origin and the keys, so the operator
can tell the publisher. Misspellings of `User-agent` are not accepted: a
wrongly read group boundary changes which rules apply to whom, which is a
larger risk than a missed rule.

## Readings of the PRD

Places where the PRD left room, or where two of its statements pulled
apart, and what the build does:

- **T10 fixture.** The PRD's fixture for "group with no rules" puts
  `User-agent: ExampleBot` before `User-agent: *` with a blank line between.
  Under RFC 9309 and P8 those two lines form one group, which then holds
  `Disallow: /`. The fixture used here puts the rule-less ExampleBot group at
  the end of the file, which is the only place a named group with no rule
  lines can occur, and tests P10 and G6 as intended.
- **T30 lengths.** `Allow: /page` (5 octets) and `Disallow: /*page` (6) are
  not of equal length under M3. The tie test uses `Disallow: /*age` (5), and
  a second assertion checks that `/*page` wins by length.
- **Identical rules.** When merged groups repeat a rule, the copy is
  dropped at selection time. Identical rules decide identically, so this
  changes no answer and keeps matching cheap.
- **A User-agent line that names nobody** (for example `User-agent: 123`)
  still starts a new group when it follows a rule line; the group then
  applies to no token. Ignoring the line outright would attach the rules
  that follow it to the previous group.
- **3xx without a Location header** is unreachable (`redirect`).
- **Stale window** is measured from when the 2xx copy was fetched.
- **Skip list entries** match a host exactly; an entry starting with `.`
  also matches every subdomain.
- **IP-literal hosts** in private, loopback, link-local and reserved ranges,
  and `localhost`, are refused before any request. Host names that resolve
  to such addresses are refused by `wp_safe_remote_get()`; that error is
  classed `unsafe`. Hosts allowed through
  `echo_robots_guard_allowed_internal_hosts` are fetched with
  `wp_remote_get()`.
- **Contact email** is a setting added for PRD lesson 8 ("give an email
  address for opt-outs"); empty by default.
- **Internal errors in the API** return a `Decision` with
  `allowed = true` and reason `internal_error`, matching F4. An invalid token
  passed to `may_fetch()` is a caller error and throws
  `InvalidTokenException`.
- **Capabilities:** settings need `manage_options`. Clearing the cache needs
  `manage_network_options` on multisite (the cache is network-wide) and
  `manage_options` on a single site.
- **Statement wording** is passed through `esc_html__()` and filterable as a
  whole. Skip hosts are named only when
  `echo_robots_guard_statement_name_skip_hosts` returns true (T6).
- **Timing tests** enforce PRD section 9 as written when `CI=true` (GitHub
  Actions sets it) or `ERG_STRICT_TIMING=1`. On a developer machine the
  budgets are tripled.

## Performance notes

- Parsing unifies line endings, then reads each line with `strpos`,
  `substr` and `trim`. No regular expression runs over the body: an early
  build used one `preg_match_all`, and a single line padded with thousands
  of spaces exhausted the PCRE backtrack limit and silently dropped every
  rule after it. Percent
  normalisation takes a fast path when a value holds no `%` and only
  printable ASCII.
- Each rule compiles its pattern once (split on `*`, anchor flag). The
  rules for a token are selected, de-duplicated and sorted once per parsed
  file (longest first, Allow first on a tie), so the first rule that
  matches is the answer and a path that matches nothing costs one pass.
- Matching uses `strncmp` and `strpos` only. Before the middle pieces are
  searched, the last piece is looked for once; most non-matching rules stop
  there. There is no regular expression built from robots.txt and no
  backtracking.

## Security

- robots.txt is data only: never evaluated, never turned into a regular
  expression, limited to 512000 bytes and 10000 rules.
- Outbound requests: robots.txt only, over http or https, through
  `wp_safe_remote_get()` with TLS verification, no cookies, no referrer,
  no credentials; redirects followed by the plugin so each hop is checked.
- Admin: nonces and capability checks on every form handler; every value
  printed is escaped.
- SQL: table name from `$wpdb->base_prefix` only; values through
  `$wpdb->prepare()`, `replace()` and `delete()`.
