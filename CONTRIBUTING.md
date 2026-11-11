# Contributing

Thank you for helping. This is a small plugin with a narrow job, so the bar
for new features is high and the bar for fixes and tests is low.

## Setup

You need PHP 8.1 or later and Composer.

```sh
git clone https://github.com/IndianOpinion/echo-robots-guard.git
cd echo-robots-guard
composer install
```

No WordPress install is needed for development: the tests run against a
small stub layer in `tests/stubs/`.

## Tests, analysis and style

```sh
composer test      # PHPUnit 10
composer analyse   # PHPStan, level 8
composer lint      # PHP_CodeSniffer, PSR-12 (see phpcs.xml.dist)
composer check     # all three
```

- `tests/Unit/` covers the pure parser and matcher. No WordPress functions
  may be used there.
- `tests/Integration/` covers the WordPress layer through the stubs.
- Fixtures: robots.txt files in `tests/fixtures/robots/`, HTTP responses in
  `tests/fixtures/http/`.
- Each behaviour case in `docs/PRD.md` section 4 maps to a test named after
  its section 12 identifier (`testT42...`). Keep that mapping when you add
  cases.
- Timing tests use the PRD budgets as written when `CI=true` or
  `ERG_STRICT_TIMING=1`; locally they allow three times as long.

CI runs lint, phpcs, PHPStan and PHPUnit on PHP 8.1, 8.2 and 8.3. Write
8.1-compatible code.

## Style

- `declare(strict_types=1);` in every PHP file.
- Typed exceptions under `src/Exception/`; no silent failures and no `@`.
- No `eval`, no shell calls. A test fails the build if they appear.
- In WordPress output, escape everything; admin actions need a nonce and a
  capability check.
- Prose in docs and strings: short sentences, plain words, British spelling.

## Pull requests

- One change per pull request, with tests.
- Describe the behaviour change and which PRD case it touches.
- Update `CHANGELOG.md` under "Unreleased".
- A sign-off line (DCO) is not required.
- By contributing you agree that your work is released under the MIT
  licence of this project.

## Reporting bugs

Use the bug report template. For security issues, see
[SECURITY.md](SECURITY.md) and do not open a public issue.
