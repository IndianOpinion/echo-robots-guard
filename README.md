# echo-robots-guard

A WordPress plugin that reads the publisher's robots.txt before the Echo RSS
Post Importer downloads an article page, and skips the item when robots.txt
disallows it for your product token. Without it, nothing in that path reads
robots.txt, so a /bot page that says "we honour robots.txt" is not true.

**Measured result:** an audit of one news site's feed rules found that a
meaningful share pointed at paths the publishers' robots.txt disallowed. That
is what made a guard at the download step necessary.

This project came out of the work on indianopinion.org and is written from
scratch; it is independent of and not affiliated with any third-party plugin
it integrates with.

## Contents

- [Install](#install)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [How it works](#how-it-works)
- [WP-CLI](#wp-cli)
- [Hooks and API](#hooks-and-api)
- [Limitations](#limitations)
- [FAQ](#faq)
- [Licence](#licence)

## Install

Requirements: WordPress 6.4 or later, PHP 8.1 or later. No Composer at
runtime.

### Without Composer (normal plugin)

1. Download the release zip, or `git clone` this repository into
   `wp-content/plugins/echo-robots-guard/`.
2. Activate **Echo Robots Guard** under Plugins. On multisite you may
   network-activate it; every site gets defaults on first use.

### With Composer

```sh
composer require indianopinion/echo-robots-guard
```

With `composer/installers` (most Bedrock-style setups) the package lands in
your plugins directory because its type is `wordpress-plugin`. Activate it as
above.

### As a must-use plugin

WordPress loads single files from `wp-content/mu-plugins/`, not folders. So:

1. Copy the plugin folder to `wp-content/mu-plugins/echo-robots-guard/`.
2. Copy `mu-loader/echo-robots-guard-loader.php` to
   `wp-content/mu-plugins/echo-robots-guard-loader.php`. It is one line:
   `require_once WPMU_PLUGIN_DIR . '/echo-robots-guard/echo-robots-guard.php';`
3. Optionally fix settings in code with constants (see
   [`examples/must-use-config.php`](examples/must-use-config.php)).

There is no activation hook for must-use plugins: the cache table is created
on first use and settings get defaults on first read. WordPress never runs
`uninstall.php` for a must-use plugin; see the FAQ for removing its data.

## Quick start

1. Activate the plugin. Out of the box it **enforces**: disallowed items are
   skipped.
2. Open **Tools > Echo Robots Guard**. Check the product token. It defaults
   to your site title in CamelCase plus `Bot` (for "Daily Example" that is
   `DailyExampleBot`). Publishers write `User-agent: DailyExampleBot` rules
   for this name, so pick it once and keep it. Press **Confirm and save**.
3. On a site that already imports, consider **shadow** mode for a week first:
   it logs what would be skipped without skipping. Read the log with an
   editor, then switch to enforce.
4. Publish a real page at `/bot` with the statement shortcode:

   ```text
   [echo_robots_guard_statement]
   ```

   The statement is generated from the live settings, so it always says what
   the plugin is actually doing.
5. Audit your feed URLs (the plugin cannot check those, see
   [Limitations](#limitations)):

   ```sh
   wp echo-robots-guard check - < feed-urls.txt
   ```

## Configuration

Every setting is per site. Each is resolved as: constant if defined, else
the saved value, else the default; then its filter; then validation. An
invalid value falls back to the default and the admin page says which one.

| Setting | Default | Range | Constant | Filter |
|---|---|---|---|---|
| Mode | `enforce` | `enforce`, `shadow`, `off` | `ECHO_ROBOTS_GUARD_MODE` | `echo_robots_guard_mode` |
| Product token | site title in CamelCase + `Bot`, else `WordPressImporterBot` | 1 to 64 of `A-Z a-z _ -` | `ECHO_ROBOTS_GUARD_TOKEN` | `echo_robots_guard_token` |
| Failure policy | `closed` | `closed`, `open` | `ECHO_ROBOTS_GUARD_FAIL_POLICY` | `echo_robots_guard_fail_policy` |
| Cache TTL | 86400 s (24 h) | 300 to 604800 s | `ECHO_ROBOTS_GUARD_TTL` | `echo_robots_guard_ttl` |
| TTL after an error | 3600 s (1 h) | 60 s up to the cache TTL | `ECHO_ROBOTS_GUARD_ERROR_TTL` | `echo_robots_guard_error_ttl` |
| Stale copy window | 2592000 s (30 days) | 0 (off) to 7776000 s | `ECHO_ROBOTS_GUARD_STALE_WINDOW` | `echo_robots_guard_stale_window` |
| Fetch timeout | 8 s | 1 to 30 s | `ECHO_ROBOTS_GUARD_TIMEOUT` | `echo_robots_guard_timeout` |
| robots.txt fetches per request | 20 | 1 to 10000 | `ECHO_ROBOTS_GUARD_MAX_FETCHES` | `echo_robots_guard_max_fetches_per_request` |
| Bot page URL | home URL + `bot` | any http(s) URL | `ECHO_ROBOTS_GUARD_BOT_PAGE_URL` | `echo_robots_guard_bot_page_url` |
| Contact email | empty | an email address | `ECHO_ROBOTS_GUARD_CONTACT_EMAIL` | `echo_robots_guard_contact_email` |
| Skip hosts | empty | host names; a leading `.` also matches subdomains | `ECHO_ROBOTS_GUARD_SKIP_HOSTS` | `echo_robots_guard_skip_hosts` |
| Max bytes parsed | 512000 | at least 512000 | none | `echo_robots_guard_max_bytes` |
| Max rules kept | 10000 | 0 or more | none | `echo_robots_guard_max_rules` |
| Hook priority | 20 | integer, read once at load | none | `echo_robots_guard_priority` |
| Internal hosts allowed | empty | host names | none | `echo_robots_guard_allowed_internal_hosts` |

A cache TTL above 24 hours is accepted with a warning, because RFC 9309 2.4
says a crawler should not use a cached copy for longer than that.

## How it works

```text
Echo item URL
  -> echo_filter_dup_check (duplicate checks run first at priority 10)
  -> our callback at priority 20
       value already true?          -> return true (passed through)
       not an http(s) URL?          -> return it untouched
       host on the skip list?       -> return it untouched (not checked)
       robots.txt for scheme://host[:port]
         per-request memo -> object cache -> plugin table -> fetch
       RFC 9309 match for your token
       allowed                      -> return the original value
       disallowed, enforce          -> return true (Echo skips the item)
       disallowed, shadow           -> return the original value, log it
```

- **Parsing** follows RFC 9309: groups, exact product-token match (never a
  prefix), merged groups, `*` fallback only when no group names your token,
  longest match wins, Allow wins a tie, `*` and `$`, percent-encoding
  normalisation, a 500 KiB parse limit, and `/robots.txt` always allowed.
  The parser and matcher are plain PHP classes under `src/Robots/` with no
  WordPress dependency; [`examples/check-robots-standalone.php`](examples/check-robots-standalone.php)
  uses them on their own.
- **HTTP status:** 2xx is parsed; 4xx means "no file, allow all"; 5xx,
  timeouts, DNS, TLS and refused connections mean "unreachable". **429 is
  read as unreachable**, not as 4xx: it means "come back later", not "there
  is no file". RFC 9309 groups it with 4xx; this is a deliberate departure.
  Up to five redirects are followed (more means "allow all"); a redirect to a
  non-HTTP scheme is unreachable.
- **Unreachable** with **fail closed** (the default, and the RFC rule) means
  every URL on that origin is disallowed, unless a 2xx copy fetched within
  the stale window is held, in which case that copy decides. **Fail open**
  allows instead, and the statement says the site is not RFC 9309 compliant
  in that respect.
- **Cache:** one row per origin in a network-wide table, with the object
  cache in front. It stores parsed groups, never the raw body, and never
  the group chosen for a token, so changing the token takes effect at once.
  Errors are cached for an hour, successes for a day. A daily cron job
  deletes rows that are expired and hold no usable stale copy.
- **Safety:** robots.txt is fetched with `wp_safe_remote_get()`, so a feed
  item cannot make the site probe private, loopback or link-local addresses.
  No cookies, referrer or credentials are sent. Matching is linear: a
  hostile pattern such as `/*a*a*a*a*b` cannot stall the import.
- **Never stops an import:** any error inside the plugin is caught at the
  hook, counted, logged once to the PHP error log, and the item passes.
- **Counters and the blocked log** are buffered for the request and written
  once at shutdown, to non-autoloaded options.

The full design, with every decision, is in
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md). The behaviour it implements is
in [docs/PRD.md](docs/PRD.md).

## WP-CLI

```sh
wp echo-robots-guard check <url>... [--token=<token>] [--fresh] [--format=table|json|csv]
wp echo-robots-guard check - < urls.txt      # one URL per line; "#" lines skipped
wp echo-robots-guard cache list [--format=...]
wp echo-robots-guard cache clear [<origin>]  # e.g. https://news.example
wp echo-robots-guard stats [--reset]
wp echo-robots-guard log [--limit=<n>]
wp echo-robots-guard status
```

`check` exits with status 1 when any URL is disallowed, 0 otherwise, so it
works in scripts and cron.

## Hooks and API

```php
use function IndianOpinion\EchoRobotsGuard\may_fetch;

$decision = may_fetch('https://news.example/story');      // your token
$decision = may_fetch('https://news.example/story', 'OtherBot');
$decision->allowed;   // true, false, or null when not checked
$decision->reason;    // rule, no_rule, robots_txt, unavailable, unreachable,
                      // stale_copy, fetch_cap, skip_list, not_http_url, internal_error
$decision->rule;      // ['agent' => ..., 'directive' => ..., 'pattern' => ...] or null
$decision->cache;     // hit, miss, stale, none

// Without a hard dependency on this plugin: null when it is not active.
$ok = apply_filters('echo_robots_guard_may_fetch', null, $url);
```

`may_fetch()` answers the robots.txt truth in every mode; the mode only
controls what the Echo callback does with the answer. See
[`examples/another-importer.php`](examples/another-importer.php).

| Hook | Type | Arguments |
|---|---|---|
| `echo_robots_guard_may_fetch` | filter | `null`, URL, optional token |
| `echo_robots_guard_statement` | filter | HTML, mode, settings array |
| `echo_robots_guard_statement_name_skip_hosts` | filter | `false`; return `true` to list skip hosts in the statement |
| `echo_robots_guard_user_agent` | filter | user agent, token |
| `echo_robots_guard_counters` | filter | counters (read-only view) |
| `echo_robots_guard_decision` | action | URL, `Decision`, context (`echo` or `api`) |
| `echo_robots_guard_blocked` | action | URL, rule (array, or text such as "unreachable, fail closed"), mode, `Decision` |
| `echo_robots_guard_fetched` | action | origin, outcome (`ok`, `unavailable`, `unreachable`), HTTP status or null, bytes |

The robots.txt fetch sends `User-Agent: <token>/<version> (+<bot page URL>)`.

## Limitations

- **The feed URL is never checked.** Echo applies no filter before it
  fetches the feed itself, so the plugin cannot see that request. The
  statement says so. The operator-side policy that covers it:
  1. Before adding a feed, run `wp echo-robots-guard check <feed URL>`.
  2. Review existing feeds on a schedule with
     `wp echo-robots-guard check - < feed-urls.txt`.
  3. Remove or pause any feed whose URL is disallowed for your token.
  4. Publish an email address on the /bot page (the contact email setting)
     so a publisher can ask for a feed to be removed.
- **Only the article download is guarded,** and only for Echo, through its
  public filter `echo_filter_dup_check`. A callback at a higher priority
  than 20 can still change the result; that is outside this plugin's
  control.
- **The importer's own User-Agent is not changed.** The plugin names itself
  only on its robots.txt fetch. That is why every decision is keyed on the
  product token, and why the statement asks publishers to write rules for
  the token.
- **Aggregator redirect links** (for example Google News article links) are
  checked against the aggregator's robots.txt, which speaks for the
  aggregator, not the publisher. Put such hosts on the skip list; the
  statement then says some hosts are not checked.
- **Crawl-delay, sitemaps and HTTP cache headers** on robots.txt are
  ignored on purpose. Crawl-delay is not part of RFC 9309. The plugin does
  not pace or rate-limit the importer.
- **Worst-case time per import run** is the number of new origins times the
  fetch timeout. The per-request fetch cap (20 by default) bounds it; for
  runs with many new hosts, a shorter timeout helps. Items past the cap use
  a stale copy if one is held, else the failure policy, and are checked
  properly on a later run. WP-CLI `check` is not capped.
- **Counters are best effort.** Two requests that flush at the same moment
  can lose a few increments. Decisions are never affected.
- **The /bot page.** WordPress may answer an unknown `/bot` with a 301 to an
  unrelated post whose slug starts with "bot" (its 404 permalink guess).
  Publish a real page at that path; the plugin does not create pages. After
  changing the mode, purge the /bot page from any page cache, since the
  statement is rendered into the cached HTML.

## FAQ

**Why enforce by default and not shadow?** So the public statement is true
from the first import. Shadow mode is there for a site that already imports
and wants to see what would be skipped first. See ARCHITECTURE.md, decision 3.

**Why is a 429 not treated like a 404?** A 404 says there is no file. A 429
says the server is busy. Reading it as "allow everything" would reward the
case where a publisher is already under load.

**Does it accept typos like `Dissallow`?** Yes, a short list of common
misspellings of Disallow and Allow is accepted, and each fetch that needed
one is counted and logged. The list is `Parser::MISSPELLINGS`.

**Does it work with a persistent object cache?** Yes. Entries live in the
object cache with the plugin table behind it. Losing either only costs a
read or a refetch.

**How do I see that it works?** Tools > Echo Robots Guard shows counters
(Echo and API apart), the last 100 blocked or would-block URLs with the rule
that matched, every cached origin, and origins unreachable for more than 7
days. `wp echo-robots-guard stats` and `log` show the same.

**How do I remove its data after using it as a must-use plugin?** Delete the
options `echo_robots_guard_settings`, `echo_robots_guard_counters` and
`echo_robots_guard_log` on each site, the network option
`echo_robots_guard_db_version`, and drop the table
`{base_prefix}echo_robots_guard_cache`. As a normal plugin, deleting it from
the Plugins screen does all of this on every site.

**Does it phone home?** No. The only outbound request is the robots.txt
fetch for each origin your importer reads.

## Licence

MIT. See [LICENSE](LICENSE).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for setup, tests and pull request rules.
