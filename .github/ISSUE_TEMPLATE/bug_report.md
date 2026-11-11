---
name: Bug report
about: Something does not work as the README or docs/PRD.md says
title: ''
labels: bug
assignees: ''
---

**What happened**

A clear description of the problem.

**What you expected**

What should have happened, ideally with the PRD case (for example "H8") or
README section.

**How to reproduce**

1. Settings (mode, token, failure policy; `wp echo-robots-guard status` output helps)
2. The URL checked and the robots.txt it was checked against (trim it to the lines that matter)
3. Output of `wp echo-robots-guard check <url> --fresh --format=json`

**Environment**

- Plugin version:
- WordPress version:
- PHP version:
- Single site or multisite; normal or must-use install:
- Persistent object cache (Redis, Memcached, none):
- Importer and version:

**Anything else**

Logs (lines starting with `[echo-robots-guard]` in the PHP error log), screenshots.
Please remove anything private before posting.
