# Repository instructions for Claude

## General Rules
1. Don’t assume. Don’t hide confusion. Surface tradeoffs.
2. Minimum code that solves the problem. Nothing speculative.
3. Touch only what you must. Clean up only your own mess.
4. Define success criteria. Loop until verified.

## Quality pipeline — required after every code change

Run these in order before declaring work complete or committing. Stop on the
first failure and fix the root cause before moving on. Don't skip steps,
don't suppress findings with `@phpstan-ignore`, and don't bypass `pint` by
hand-formatting around it.

```bash
composer rector       # 1. semantic refactorings (readonly, types, dead code)
composer pint         # 2. PSR-12 style — runs after rector to smooth its output
composer test         # 3. PHPUnit — must be green
composer stan         # 4. PHPStan level 8 — must be 0 errors
```

Order matters: rector and pint both reformat, so style runs after refactor.
Tests come before stan because a real bug usually fails a test before it
fails type analysis, and the test failure is a more direct signal.

Each tool has a dry/preview mode if you want to inspect first:

- `composer rector:dry` — show what rector would change
- `composer pint:test`  — show what pint would change

## Configuration files (don't edit casually)

- `phpunit.xml` — test runner
- `phpstan.neon` — level 8 over `app/`, `tests/`, `public/`
- `rector.php` — code-quality / dead-code / early-return / type-declaration sets,
  with `RemoveUnusedPublicMethodParameterRector` and the closure-to-arrow
  rules deliberately skipped (they break PSR-15-shaped handlers and
  collapse readable multi-line closures)
- `pint.json` — PSR-12 preset, project-wide

## Other repository conventions

- PHP 8.2+. Strict types in every file (`declare(strict_types=1)`).
- PSR-4 autoload: `RepAhead\\` → `app/`, `RepAhead\\Tests\\` → `tests/`.
- Tests sit beside the unit they cover (e.g. `app/Cache.php` ↔ `tests/CacheTest.php`).
- The implementation plan lives at `docs/superpowers/plans/` and the design
  spec at `docs/superpowers/specs/`. Both are committed history — read them
  before making structural changes.
