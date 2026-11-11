# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | yes |

## Reporting a vulnerability

Please email **hello@indianopinion.org** with "echo-robots-guard security"
in the subject. Do not open a public issue.

Include what you can: the version, a description, steps to reproduce, and
the impact you see.

What to expect:

- an acknowledgement within 3 working days;
- an assessment and a plan within 10 working days;
- a fix released, and credit if you want it, once users can update.

We ask you to keep the report private until a fix is released, or for 90
days, whichever comes first.

## Scope

In scope: this plugin's code, including server-side request forgery through
the robots.txt fetch, cross-site scripting or request forgery in the admin
page, SQL in the cache table, and anything that lets a hostile robots.txt
or feed item stall or break an import.

Out of scope: the Echo importer and other third-party plugins, and
WordPress core.
