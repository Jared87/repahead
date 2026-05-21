# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

A private Composer (Packagist-compatible) repository server. Publishers drop Release ZIPs into Storage (`zips/vendor/package/version.zip`); the service scans them into a Listing, builds a `packages.json` Index, and serves Releases over HTTP with basic auth. Storage is pluggable via Flysystem (local disk or S3).

Three endpoints: `GET /packages.json` (cached Index), `GET /dist/{vendor}/{package}/{version}.zip` (stream a Release), `POST /rebuild` (force Index rebuild).

See `CONTEXT.md` for the domain glossary.

## General Rules
1. Don't assume. Don't hide confusion. Surface tradeoffs.
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

## Running a single test

```bash
./vendor/bin/phpunit tests/Http/ControllerTest.php         # one file
./vendor/bin/phpunit --filter testRebuildReturnsStats      # one method
```

## Local development

```bash
composer install
cp .env.example .env      # set AUTH_PASS at minimum
php -S 127.0.0.1:8080 -t public
```

Docker alternative (see README for full usage):

```bash
AUTH_PASS=$(grep AUTH_PASS .env | cut -d= -f2) docker compose up -d --build
```

## Architecture

Three layers — understanding how they interact requires reading across files:

- **HTTP** (`app/Http/`): `Auth` is a PSR-15 middleware for basic auth; `Controller` holds the three request handlers; `SafeJsonStrategy` is a JSON response strategy that catches exceptions and never leaks internal paths to clients.
- **Catalog** (`app/Catalog/`): `Catalog` scans Storage and returns a Listing of `Release` value objects; `PackagesJson` builds the Index from the Listing; `ZipMetadata` reads `composer.json` from inside each Release ZIP to extract name/require/autoload.
- **Cross-cutting** (`app/`): `App` owns the router and wraps all dispatch in a safe catch-all; `Cache` provides file-based TTL + Listing Fingerprint matching with file-locking for concurrency; `Config` validates env vars at boot; `Storage` is a Flysystem factory driven by a `STORAGE_DSN` (`local:./zips` or `s3:bucket/prefix`).

Cache invalidation is two-tier: `LISTING_TTL_SECONDS` controls how often Storage is re-listed; the Listing Fingerprint (SHA-256 of the sorted Listing) skips an Index rebuild when the Release set hasn't changed. See `docs/adr/0001-two-tier-cache-invalidation.md`.

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
- Tests mirror the `app/` layout: `app/Http/Controller.php` ↔ `tests/Http/ControllerTest.php`, `app/Catalog/Catalog.php` ↔ `tests/Catalog/CatalogTest.php`. Cross-cutting tests (Config, Cache, Storage, EndToEnd, Smoke) stay at `tests/` root, matching the cross-cutting production files at `app/` root.
- Domain vocabulary is in `CONTEXT.md`. Use it in code, comments, and commit messages.
- The implementation plan lives at `docs/superpowers/plans/` and the design
  spec at `docs/superpowers/specs/`. Both are committed history — read them
  before making structural changes.
