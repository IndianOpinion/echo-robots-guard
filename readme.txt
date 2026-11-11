=== Echo Robots Guard ===
Contributors: indianopinion
Tags: robots.txt, rss, importer, crawler, rfc9309
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Checks the publisher's robots.txt (RFC 9309) before the Echo RSS importer downloads an article page, and skips pages disallowed for your product token.

== Description ==

A site that imports news with the Echo RSS Post Importer reads a feed, then downloads each linked article page. Nothing in that path reads the publisher's robots.txt. This plugin hooks Echo's public filter `echo_filter_dup_check`, checks each article URL against the publisher's robots.txt for your product token, and tells Echo to skip the item when it is disallowed.

* Full RFC 9309 matching: groups, exact product-token match, merged groups, `*` fallback, longest match with Allow on a tie, `*` and `$`, percent-encoding normalisation, a 500 KiB parse limit.
* Modes: enforce (default), shadow (log only) and off.
* Failure policy for unreachable robots.txt: fail closed (RFC 9309) or fail open.
* Cache with a 24-hour TTL, a 1-hour TTL after errors and a 30-day stale copy.
* A shortcode, `[echo_robots_guard_statement]`, that prints a statement for your /bot page matching the mode the plugin is in.
* Tools page with counters, the last 100 blocked URLs and the rule that matched, and the cache.
* WP-CLI: `wp echo-robots-guard check|cache|stats|log|status`.
* A general API, `IndianOpinion\EchoRobotsGuard\may_fetch()` and the `echo_robots_guard_may_fetch` filter, for any importer.
* Works as a normal plugin, network-activated, or as a must-use plugin.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

Limitation: Echo applies no filter before it fetches the feed itself, so the feed URL is never checked. Check feed URLs with `wp echo-robots-guard check` before adding them.

== Installation ==

1. Upload the `echo-robots-guard` folder to `wp-content/plugins/`, or install the zip from Plugins > Add New.
2. Activate it.
3. Go to Tools > Echo Robots Guard, confirm or change the product token, and save.
4. Publish a page at /bot containing `[echo_robots_guard_statement]`.

For a must-use install, copy the folder to `wp-content/mu-plugins/echo-robots-guard/` and `mu-loader/echo-robots-guard-loader.php` to `wp-content/mu-plugins/`.

== Frequently Asked Questions ==

= Does it change the importer's User-Agent? =

No. It names itself only on its own robots.txt fetch. Publishers should write rules for your product token.

= Why is 429 treated as unreachable? =

A 429 means "come back later", not "there is no file". Under fail closed the origin is blocked for the error TTL unless a recent copy is held.

= What happens if the plugin itself fails? =

The item is let through, the error is counted and one line goes to the PHP error log. A fault in the guard never stops an import.

== Changelog ==

= 0.1.0 =
* First release.
