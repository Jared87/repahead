---
title: Rename namespace Composerd → RepAhead and src/ → app/
date: 2026-05-06
status: design
---

# Namespace + Source-Folder Rename — Design

## Goal

Rename the project's PHP namespace from `Composerd` to `RepAhead` and the
production source folder from `src/` to `app/`. Drag the cosmetic test
temp-file prefixes (`composerd-…`) along with the namespace.

The rename is purely mechanical. No behavioural change, no API change visible
to consumers. Public HTTP routes, response shapes, env vars, the composer
package name (`repahead/composer-server`), and the repository directory
(`/Users/kl3tte/development/repahead/`) all stay the same.

## Non-goals

- Don't rewrite the design spec or implementation plan under
  `docs/superpowers/specs/` and `docs/superpowers/plans/`. They are
  point-in-time artifacts; rewriting them falsifies the audit trail.
  They will keep saying "Composerd" and "src/" and that's correct.
- Don't rename `tests/` or `public/`.
- Don't change runtime semantics — no fix-ups, no opportunistic refactors.

## What changes

### 1. PHP namespace

| Before              | After             |
|---------------------|-------------------|
| `Composerd`         | `RepAhead`        |
| `Composerd\Tests`   | `RepAhead\Tests`  |
| `Composerd\Tests\Support` | `RepAhead\Tests\Support` |

Affects:

- Every `namespace …;` line in `app/`, `tests/`, and (incidentally) the
  test classes that already moved into the new tree.
- Every `use Composerd\…;` import.
- Every fully-qualified reference written `\Composerd\…` (PHPDoc tags,
  inline FQNs in tests, string class names if any).

### 2. Source folder

`src/` → `app/`. The directory itself, plus references in:

| File              | Reference type                                     |
|-------------------|----------------------------------------------------|
| `composer.json`   | PSR-4 autoload map (`"Composerd\\": "src/"`)       |
| `phpunit.xml`     | Coverage `<source><include><directory>src</directory>` |
| `phpstan.neon`    | `paths:` list                                      |
| `rector.php`      | `withPaths([__DIR__ . '/src', …])`                 |
| `Dockerfile`      | `COPY --chown=www-data:www-data src ./src`         |
| `CLAUDE.md`       | Reference lines under "Repository conventions"     |

### 3. Runtime strings (cosmetic)

Find/replace `composerd-` → `repahead-` in `tests/` and `app/`. Hits
temp-file name prefixes used for unique-per-test scratch directories:

- `composerd-cache-`, `composerd-ctrl-`, `composerd-e2e-`,
  `composerd-storage-`, `composerd-zip` (in `app/ZipMetadata.php` and
  `tests/Support/ZipBuilder.php`).

These are not types or identifiers — pure cosmetic — but consistency helps
when grepping crash-recovery logs.

## What stays unchanged

- Repo path: `/Users/kl3tte/development/repahead/` (already lowercase).
- Composer package name: `repahead/composer-server`.
- `tests/`, `public/`, `cache/`, `zips/` folder names.
- HTTP routes, response shapes, env-var names, `.env.example`.
- `Dockerfile` base image (`serversideup/php:8.4-frankenphp`) and
  `compose.yml` apart from any path that mentions `src/` (none currently).
- The plan/spec/README under `docs/superpowers/` (historical artifacts).

## Approach (single commit)

A coherent rename should land as one commit so the repo never sits in an
unbuildable state.

1. `git mv src app`.
2. Find/replace across `app/`, `tests/`, `public/`:
   - `namespace Composerd` → `namespace RepAhead`
   - `use Composerd\` → `use RepAhead\`
   - `\Composerd\` → `\RepAhead\` (catches PHPDoc and inline FQNs)
   - `composerd-` → `repahead-`
3. Update `composer.json` PSR-4 map:
   - `"Composerd\\": "src/"` → `"RepAhead\\": "app/"`
   - `"Composerd\\Tests\\": "tests/"` → `"RepAhead\\Tests\\": "tests/"`
4. Update `phpunit.xml`, `phpstan.neon`, `rector.php`, `Dockerfile`
   path references from `src` to `app`.
5. Update `CLAUDE.md` doc lines that reference the old namespace/path.
6. `composer dump-autoload` to regenerate PSR-4 mappings.
7. Run the project's quality pipeline (per `CLAUDE.md`):
   `composer rector` → `composer pint` → `composer test` → `composer stan`.
   All four must be green before commit.

## Verification

The existing pipeline gives high coverage on this kind of rename:

- **`composer test`** — every namespace must still resolve, every `use` must
  still find the right class. A missed `Composerd\Foo` import becomes a
  fatal error at autoload time and the test that touches it fails.
- **`composer stan`** — catches PHPDoc and string-class-name references that
  the test runner doesn't exercise (e.g. `@param array{vendor: string,…}`,
  `\Composerd\PackagesJsonResult` in the assert call).
- **`composer pint`** — enforces the post-rename style baseline.
- **`composer rector`** — confirms no follow-on type-narrowing or dead-code
  issues surfaced by the new code shape.

## Failure modes to expect

- **PHPDoc strings.** `@param Composerd\X $foo` style annotations need the
  `\Composerd\` → `\RepAhead\` pass to catch them — a plain `Composerd` →
  `RepAhead` substitution would also work but the FQN form is unambiguous.
  PHPStan will flag any miss.
- **Test-only Support namespace.** `Composerd\Tests\Support\ZipBuilder` is
  used in a couple of inline FQN sites (e.g. `\Composerd\Tests\Support\…`
  in `ControllerTest`). The `\Composerd\` → `\RepAhead\` pass covers these
  too.
- **`composer dump-autoload` skipped.** Without it, the new namespace map
  isn't reflected in `vendor/composer/autoload_*.php`, and the very next
  `phpunit` run fails with class-not-found. The pipeline itself runs
  composer scripts which trigger autoload regeneration on script start, so
  this should self-heal, but call it out explicitly.
- **Dockerfile copy.** A stale `COPY src ./src` would still work
  (the new `app/` directory wouldn't exist in the image) but the `index.php`
  autoload would fail at runtime. Caught by the next `docker compose up`,
  not by the test pipeline.
