---
title: Reorganize app/ into Http/ and Catalog/ subfolders
date: 2026-05-06
status: design
---

# `app/` Reorganization — Design

## Goal

Group the 12 files in `app/` into meaningful subfolders that reflect their
real cohesion. After the move, `app/` has two domain folders (`Http/`,
`Catalog/`) plus a small set of cross-cutting files at the root. Tests
mirror the structure 1:1.

## Why

`app/` is currently a flat list of 12 files. Three of them (`Controller`,
`Auth`, `SafeJsonStrategy`) form the HTTP layer; five (`Catalog`,
`CatalogEntry`, `PackagesJson`, `ZipMeta`, `ZipMetadata`) form the catalog
domain. A reader scanning the directory should see those clusters at a
glance.

This is a structural cleanup. No behaviour changes, no API changes. The
move is mechanical: relocate files, update `namespace` declarations,
update `use` imports at consumers.

## Non-goals

- Don't split `PackagesJsonResult` from `PackagesJson.php`. PSR-4 tolerates
  tightly-coupled types in one file; splitting is a separate cleanup.
- Don't rename any classes (`Storage` stays `Storage`, `Cache` stays
  `Cache`, etc.).
- Don't introduce a Laravel-strict `Http/Controllers/` + `Http/Middleware/`
  split. Premature for one controller and one middleware.
- Don't move `App.php`, `Config.php`, `Cache.php`, or `Storage.php`. Each
  is a top-level cross-cutting unit; keeping them at the root avoids the
  `RepAhead\Storage\Storage` namespace duplication and keeps the root
  listing scannable.
- Don't rewrite the historical specs and plans under `docs/superpowers/`.
  They're audit-trail artifacts.

## Target layout

### Production code (`app/`)

```
app/
├── App.php           # top-level factory: Router + middleware + Controller
├── Cache.php         # local manifest-hash cache (filesystem ops)
├── Config.php        # env validation + typed getters
├── Storage.php       # Flysystem factory (DSN -> Filesystem)
├── Http/
│   ├── Auth.php             # PSR-15 basic-auth middleware
│   ├── Controller.php       # /packages.json, /dist/..., /rebuild handlers
│   └── SafeJsonStrategy.php # League Route strategy with sanitised 500s
└── Catalog/
    ├── Catalog.php          # walks Filesystem, returns CatalogEntry[] + hash
    ├── CatalogEntry.php     # value object (vendor, package, version, ...)
    ├── PackagesJson.php     # builder + PackagesJsonResult DTO
    ├── ZipMeta.php          # value object (composerJson, sha1)
    └── ZipMetadata.php      # extracts composer.json + sha1 from a ZIP
```

### Tests (`tests/`)

Mirror the layout. `EndToEndTest` and `SmokeTest` stay at the root
(spans/cross-cuts). `Support/` is unchanged — `ThrowingFilesystem` and
`ZipBuilder` are test fixtures, not domain.

```
tests/
├── ConfigTest.php
├── CacheTest.php
├── StorageTest.php
├── EndToEndTest.php
├── SmokeTest.php
├── Http/
│   ├── AuthTest.php
│   └── ControllerTest.php
├── Catalog/
│   ├── CatalogTest.php
│   ├── PackagesJsonTest.php
│   └── ZipMetadataTest.php
└── Support/
    ├── ThrowingFilesystem.php
    └── ZipBuilder.php
```

## Namespace fallout (PSR-4 standard)

The PSR-4 root mappings stay `RepAhead\\` → `app/` and
`RepAhead\\Tests\\` → `tests/`; PSR-4 derives sub-namespaces from
sub-directories automatically.

| File                       | New namespace                   |
|----------------------------|---------------------------------|
| `app/App.php`              | `RepAhead\App`                  |
| `app/Cache.php`            | `RepAhead\Cache`                |
| `app/Config.php`           | `RepAhead\Config`               |
| `app/Storage.php`          | `RepAhead\Storage`              |
| `app/Http/Auth.php`        | `RepAhead\Http\Auth`            |
| `app/Http/Controller.php`  | `RepAhead\Http\Controller`      |
| `app/Http/SafeJsonStrategy.php` | `RepAhead\Http\SafeJsonStrategy` |
| `app/Catalog/Catalog.php`  | `RepAhead\Catalog\Catalog`      |
| `app/Catalog/CatalogEntry.php`  | `RepAhead\Catalog\CatalogEntry` |
| `app/Catalog/PackagesJson.php`  | `RepAhead\Catalog\PackagesJson` (and `RepAhead\Catalog\PackagesJsonResult` in the same file) |
| `app/Catalog/ZipMeta.php`  | `RepAhead\Catalog\ZipMeta`      |
| `app/Catalog/ZipMetadata.php`   | `RepAhead\Catalog\ZipMetadata`  |

Test files mirror this: `tests/Http/AuthTest.php` →
`RepAhead\Tests\Http\AuthTest`, etc.

## What stays unchanged

- Repo path, composer package name `repahead/composer-server`.
- Root namespace `RepAhead\`.
- Class names (no renames).
- File contents apart from the `namespace` line and `use` statements.
- Public HTTP behaviour, response shapes, env vars, `.env.example`.
- `composer.json` autoload map (PSR-4 sub-namespaces are derived from
  sub-directories — no entry-list change).
- `phpunit.xml`, `phpstan.neon`, `rector.php`, `pint.json`, `Dockerfile`,
  `compose.yml`, `CLAUDE.md`. They reference `app/` and `tests/` at the
  top level; nested files are picked up by recursion.

## Approach (single commit)

A coherent move should land as one commit so the repo never sits in a
half-broken state.

1. `git mv` 8 production files and 5 test files into their new homes.
2. Update each moved file's `namespace` declaration.
3. Update `use` imports at every consumer:
   - `app/App.php` imports the moved classes when wiring the router.
   - `app/Http/Controller.php` imports `RepAhead\Catalog\…` types.
   - Each moved test imports the SUT from its new namespace.
   - `public/index.php` does NOT need changes (it imports `RepAhead\App`,
     `RepAhead\Config`, `RepAhead\Storage` — all root-level, unchanged).
4. `composer dump-autoload` to regenerate the autoload index.
5. Run the full quality pipeline (`composer rector` → `pint` → `test` →
   `stan`). All four must be green before commit.

## Verification

The existing pipeline is sufficient:

- **`composer test`** — every namespace must still resolve. A missed
  `use` becomes class-not-found at autoload time and the test fails.
- **`composer stan`** — catches PHPDoc-only references the test runner
  doesn't exercise (e.g. `@param Composerd\Catalog $foo` style — though
  ours mostly use short forms or inline FQNs).
- **`composer pint`** — formats the new namespace lines consistently.
- **`composer rector`** — confirms no follow-on type-narrowing or
  dead-code findings emerged from the move.

## Failure modes to expect

- **Inline FQN references** like `\Composerd\Tests\Support\ThrowingFilesystem`
  in `tests/ControllerTest.php` are now `\RepAhead\Tests\Support\ThrowingFilesystem`
  (Support didn't move). They stay valid.
- **`new \stdClass` and other built-in references** are rooted at `\` and
  unaffected.
- **Test file rename collisions:** moving `tests/AuthTest.php` to
  `tests/Http/AuthTest.php` requires both the `git mv` and a namespace
  bump in the same change. Doing one without the other fails autoload.
- **`composer dump-autoload` not run.** PSR-4 derives sub-namespaces from
  sub-directories at autoload-build time. Without dump, the next test
  invocation can't find `RepAhead\Http\Controller`. The composer scripts
  in `composer.json` invoke `phpunit` / `phpstan` / `pint` / `rector`,
  which don't auto-regenerate; the rebuild has to be explicit.
