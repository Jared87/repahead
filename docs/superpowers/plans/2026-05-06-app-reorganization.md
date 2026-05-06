# `app/` Reorganization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move 8 PHP files in `app/` and 5 in `tests/` into `Http/` and `Catalog/` subfolders, update all `namespace` declarations and `use` imports, single commit.

**Architecture:** `git mv` for relocations, `sed` for the namespace one-liner across the moved files, hand-written file rewrites for the two consumers that pick up new `use` blocks (`Controller.php` and `App.php`). Tests get the same treatment. Verified by the project's standard pipeline: rector → pint → test → stan.

**Tech Stack:** No new dependencies. Uses BSD `sed` (macOS), `git mv`, and the existing composer scripts.

**Spec:** `docs/superpowers/specs/2026-05-06-app-reorganization-design.md`

**Working directory:** `/Users/kl3tte/development/repahead`. Branch `feat/app-reorg`.

**Why one task with many steps:** the spec mandates a single coherent commit. Intermediate states (file moved but namespace not yet updated, or namespace updated but consumers not yet) are unbuildable. The subagent runs all steps then commits once.

---

## File Structure (after the move)

```
app/
├── App.php
├── Cache.php
├── Config.php
├── Storage.php
├── Http/
│   ├── Auth.php             (was app/Auth.php)
│   ├── Controller.php       (was app/Controller.php)
│   └── SafeJsonStrategy.php (was app/SafeJsonStrategy.php)
└── Catalog/
    ├── Catalog.php          (was app/Catalog.php)
    ├── CatalogEntry.php     (was app/CatalogEntry.php)
    ├── PackagesJson.php     (was app/PackagesJson.php — still bundles PackagesJsonResult)
    ├── ZipMeta.php          (was app/ZipMeta.php)
    └── ZipMetadata.php      (was app/ZipMetadata.php)

tests/
├── ConfigTest.php
├── CacheTest.php
├── StorageTest.php
├── EndToEndTest.php
├── SmokeTest.php
├── Http/
│   ├── AuthTest.php         (was tests/AuthTest.php)
│   └── ControllerTest.php   (was tests/ControllerTest.php)
├── Catalog/
│   ├── CatalogTest.php      (was tests/CatalogTest.php)
│   ├── PackagesJsonTest.php (was tests/PackagesJsonTest.php)
│   └── ZipMetadataTest.php  (was tests/ZipMetadataTest.php)
└── Support/
    ├── ThrowingFilesystem.php
    └── ZipBuilder.php
```

Files at root unchanged: `composer.json`, `phpunit.xml`, `phpstan.neon`, `rector.php`, `pint.json`, `Dockerfile`, `compose.yml`, `CLAUDE.md`, `README.md`, `.env.example`, etc.

---

## Task 1: Reorganize `app/` and `tests/` in a single commit

This is the only task. It has 17 steps; the commit comes only after all four pipeline tools (rector/pint/test/stan) pass.

**Files:**
- Move (production): `app/Auth.php`, `app/Controller.php`, `app/SafeJsonStrategy.php`, `app/Catalog.php`, `app/CatalogEntry.php`, `app/PackagesJson.php`, `app/ZipMeta.php`, `app/ZipMetadata.php`
- Move (tests): `tests/AuthTest.php`, `tests/ControllerTest.php`, `tests/CatalogTest.php`, `tests/PackagesJsonTest.php`, `tests/ZipMetadataTest.php`
- Modify (root, no move): `app/App.php`, `tests/EndToEndTest.php`

- [ ] **Step 1: Confirm clean starting state**

```bash
git status --short
```
Expected: empty output.

- [ ] **Step 2: Move the 8 production files**

```bash
mkdir -p app/Http app/Catalog
git mv app/Auth.php app/Http/Auth.php
git mv app/Controller.php app/Http/Controller.php
git mv app/SafeJsonStrategy.php app/Http/SafeJsonStrategy.php
git mv app/Catalog.php app/Catalog/Catalog.php
git mv app/CatalogEntry.php app/Catalog/CatalogEntry.php
git mv app/PackagesJson.php app/Catalog/PackagesJson.php
git mv app/ZipMeta.php app/Catalog/ZipMeta.php
git mv app/ZipMetadata.php app/Catalog/ZipMetadata.php
```
Expected: no output. `git status --short` shows 8 lines of `R  app/X.php -> app/{Http,Catalog}/X.php`.

- [ ] **Step 3: Move the 5 test files**

```bash
mkdir -p tests/Http tests/Catalog
git mv tests/AuthTest.php tests/Http/AuthTest.php
git mv tests/ControllerTest.php tests/Http/ControllerTest.php
git mv tests/CatalogTest.php tests/Catalog/CatalogTest.php
git mv tests/PackagesJsonTest.php tests/Catalog/PackagesJsonTest.php
git mv tests/ZipMetadataTest.php tests/Catalog/ZipMetadataTest.php
```
Expected: no output. `git status --short` shows 13 total `R` lines now.

- [ ] **Step 4: Bulk-update the `namespace` declarations on the moved files**

Use four `sed` passes — one per target subfolder. The match pattern is anchored on the `namespace RepAhead;` line which is unique per file.

```bash
sed -i '' 's|^namespace RepAhead;$|namespace RepAhead\\Http;|' app/Http/*.php
sed -i '' 's|^namespace RepAhead;$|namespace RepAhead\\Catalog;|' app/Catalog/*.php
sed -i '' 's|^namespace RepAhead\\Tests;$|namespace RepAhead\\Tests\\Http;|' tests/Http/*.php
sed -i '' 's|^namespace RepAhead\\Tests;$|namespace RepAhead\\Tests\\Catalog;|' tests/Catalog/*.php
```

Verify:
```bash
grep -n '^namespace ' app/Http/*.php app/Catalog/*.php tests/Http/*.php tests/Catalog/*.php
```
Expected: each `app/Http/*.php` shows `namespace RepAhead\Http;`, each `app/Catalog/*.php` shows `namespace RepAhead\Catalog;`, each `tests/Http/*.php` shows `namespace RepAhead\Tests\Http;`, each `tests/Catalog/*.php` shows `namespace RepAhead\Tests\Catalog;`.

- [ ] **Step 5: Rewrite `app/Http/Controller.php`**

Controller now lives in `RepAhead\Http\` and references several classes from `RepAhead\Catalog\` plus `RepAhead\Cache`. Replace the imports block and drop inline FQN forms (which point at the old root namespace).

Read the file first. The structural content (constructor body, three method bodies, `jsonResponse`/`errorResponse` helpers) stays identical. Only the imports and the two `?\RepAhead\ZipMeta` annotations and the `\RepAhead\PackagesJsonResult` reference need to change.

The new top section should look like this (everything from `<?php` through the `final readonly class Controller` line):

```php
<?php

declare(strict_types=1);

namespace RepAhead\Http;

use Laminas\Diactoros\Response;
use Laminas\Diactoros\Stream;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RepAhead\Cache;
use RepAhead\Catalog\Catalog;
use RepAhead\Catalog\CatalogEntry;
use RepAhead\Catalog\PackagesJson;
use RepAhead\Catalog\PackagesJsonResult;
use RepAhead\Catalog\ZipMeta;
use RepAhead\Catalog\ZipMetadata;

final readonly class Controller
```

Inside the methods:
- Both `fn (CatalogEntry $e): ?\RepAhead\ZipMeta => $this->zipMetadata->read(...)` lines become `fn (CatalogEntry $e): ?ZipMeta => $this->zipMetadata->read(...)` (the `use RepAhead\Catalog\ZipMeta;` import lets us drop the FQN).
- The line `\assert($result instanceof \RepAhead\PackagesJsonResult);` becomes `\assert($result instanceof PackagesJsonResult);`.

Use `Edit` to swap the import block and the three inline references; or `Read` then `Write` the whole file. Either way the diff should affect ONLY: the namespace declaration (already done by step 4), the import block, and the three FQN simplifications.

- [ ] **Step 6: Rewrite `app/App.php`**

`App.php` stays at `RepAhead\App` but now needs to import six moved classes via new `use` statements.

The new top section (from `<?php` through `final class App`) should be:

```php
<?php

declare(strict_types=1);

namespace RepAhead;

use Laminas\Diactoros\Response;
use Laminas\Diactoros\ResponseFactory;
use League\Flysystem\Filesystem;
use League\Route\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RepAhead\Catalog\Catalog;
use RepAhead\Catalog\PackagesJson;
use RepAhead\Catalog\ZipMetadata;
use RepAhead\Http\Auth;
use RepAhead\Http\Controller;
use RepAhead\Http\SafeJsonStrategy;
use Throwable;

final class App
```

Method bodies stay identical: `new Controller(...)`, `new Auth(...)`, `new SafeJsonStrategy(...)`, `new Catalog()`, `new ZipMetadata(...)`, `new PackagesJson(...)`, etc. — all of those short class names now resolve through the new `use` statements above.

- [ ] **Step 7: Update `tests/Http/AuthTest.php` imports**

Change the single SUT import:
- `use RepAhead\Auth;` → `use RepAhead\Http\Auth;`

Everything else (handler helper, request builder, all five tests) stays identical.

- [ ] **Step 8: Update `tests/Http/ControllerTest.php` imports**

Replace the SUT-imports block:

```php
use RepAhead\Cache;
use RepAhead\Catalog;
use RepAhead\Controller;
use RepAhead\PackagesJson;
use RepAhead\Tests\Support\ZipBuilder;
use RepAhead\ZipMetadata;
```

with:

```php
use RepAhead\Cache;
use RepAhead\Catalog\Catalog;
use RepAhead\Catalog\PackagesJson;
use RepAhead\Catalog\ZipMetadata;
use RepAhead\Http\Controller;
use RepAhead\Tests\Support\ZipBuilder;
```

Test bodies stay identical. The three inline `new \RepAhead\Tests\Support\ThrowingFilesystem()` and the inline `\RepAhead\Tests\Support\ZipBuilder::buildBytes(...)` references remain valid because `Support/` did not move.

- [ ] **Step 9: Update `tests/Catalog/CatalogTest.php` imports**

Replace:

```php
use RepAhead\Catalog;
use RepAhead\CatalogEntry;
use RepAhead\Tests\Support\ZipBuilder;
```

with:

```php
use RepAhead\Catalog\Catalog;
use RepAhead\Catalog\CatalogEntry;
use RepAhead\Tests\Support\ZipBuilder;
```

Test bodies stay identical.

- [ ] **Step 10: Update `tests/Catalog/PackagesJsonTest.php` imports and inline FQN**

Replace the imports:

```php
use RepAhead\CatalogEntry;
use RepAhead\PackagesJson;
use RepAhead\ZipMeta;
```

with:

```php
use RepAhead\Catalog\CatalogEntry;
use RepAhead\Catalog\PackagesJson;
use RepAhead\Catalog\ZipMeta;
```

Then update the four inline FQN references inside the test bodies. Each one:
- `?\RepAhead\ZipMeta` → `?ZipMeta`
- `\RepAhead\ZipMeta` → `ZipMeta`

Concretely:

| Line (approx) | Before                                             | After |
|---------------|----------------------------------------------------|-------|
| 72            | `fn (CatalogEntry $e): ?\RepAhead\ZipMeta =>`      | `fn (CatalogEntry $e): ?ZipMeta =>` |
| 86            | `fn (): \RepAhead\ZipMeta => new ZipMeta(...)`     | `fn (): ZipMeta => new ZipMeta(...)` |
| 98            | `fn (): \RepAhead\ZipMeta => new ZipMeta(...)`     | `fn (): ZipMeta => new ZipMeta(...)` |
| 113           | `fn (): \RepAhead\ZipMeta => new ZipMeta(...)`     | `fn (): ZipMeta => new ZipMeta(...)` |

Line numbers may shift after the imports change — match by content.

- [ ] **Step 11: Update `tests/Catalog/ZipMetadataTest.php` imports**

Replace:

```php
use RepAhead\ZipMetadata;
use RepAhead\Tests\Support\ZipBuilder;
```

with:

```php
use RepAhead\Catalog\ZipMetadata;
use RepAhead\Tests\Support\ZipBuilder;
```

- [ ] **Step 12: Update `tests/EndToEndTest.php` (stays at root)**

Only one inline FQN needs to change — the `SafeJsonStrategy` reference is now in `RepAhead\Http\`.

In the test method `testSafeJsonStrategySanitisesUncaughtException`:

```php
$router->setStrategy(new \RepAhead\SafeJsonStrategy(new \Laminas\Diactoros\ResponseFactory()));
```

becomes:

```php
$router->setStrategy(new \RepAhead\Http\SafeJsonStrategy(new \Laminas\Diactoros\ResponseFactory()));
```

The rest of the file is unchanged. Namespace stays `RepAhead\Tests`.

- [ ] **Step 13: Regenerate Composer autoload mappings**

```bash
composer dump-autoload
```
Expected: `Generated autoload files...` with no errors. The new `RepAhead\Http\` and `RepAhead\Catalog\` sub-namespaces are now picked up via PSR-4 (no `composer.json` edit needed; PSR-4 maps sub-directories automatically).

- [ ] **Step 14: Final sanity grep — no stale namespace references in moved files**

```bash
grep -rEn "use RepAhead\\\\(Auth|Controller|SafeJsonStrategy|Catalog|CatalogEntry|PackagesJson|ZipMeta|ZipMetadata);" app tests public
```

Expected: no output. (If anything matches, the corresponding file references the OLD root namespace for a class that has moved — fix it by hand.)

```bash
grep -rEn "\\\\RepAhead\\\\(Auth|Controller|SafeJsonStrategy|Catalog|CatalogEntry|PackagesJson|ZipMeta|ZipMetadata|PackagesJsonResult)\\b" app tests public
```

Expected: no output OUTSIDE of `RepAhead\Tests\Support` references (which are unaffected by the move). If any non-Support match appears, check whether it points at the new namespace — `\RepAhead\Http\…` and `\RepAhead\Catalog\…` are fine, bare `\RepAhead\Auth` (for example) is a miss.

- [ ] **Step 15: Verify with rector**

```bash
composer rector:dry
```
Expected: `[OK] Rector is done!` with `0 files would have been changed`. If rector wants changes, fix and re-run.

- [ ] **Step 16: Verify with pint**

```bash
composer pint:test
```
Expected: `{"tool":"pint","result":"passed"}`. If pint flags issues, run `composer pint` to apply, then re-run `pint:test`.

- [ ] **Step 17: Verify with phpunit and phpstan**

```bash
composer test
composer stan
```
Expected:
- `OK (55 tests, 117 assertions)`
- `[OK] No errors`

If tests fail with class-not-found, the most likely cause is a missed `use` import — re-run the sanity greps from Step 14. If phpstan flags type issues, the most likely cause is a missed inline FQN in a PHPDoc tag — open the offending file and fix.

- [ ] **Step 18: Commit**

```bash
git add -A
git commit -m "$(cat <<'EOF'
refactor: reorganize app/ into Http/ and Catalog/ subfolders

Move 8 production files and 5 test files into domain subfolders as designed
in docs/superpowers/specs/2026-05-06-app-reorganization-design.md.

- app/Http/        Auth, Controller, SafeJsonStrategy
- app/Catalog/     Catalog, CatalogEntry, PackagesJson (+PackagesJsonResult),
                   ZipMeta, ZipMetadata
- app/ root        App, Cache, Config, Storage  (cross-cutting; unchanged)

Test layout mirrors the production layout:
- tests/Http/      AuthTest, ControllerTest
- tests/Catalog/   CatalogTest, PackagesJsonTest, ZipMetadataTest
- tests/ root      ConfigTest, CacheTest, StorageTest, EndToEndTest, SmokeTest

Pure relocation: namespaces updated for moved files, use imports updated for
their consumers (App.php, Controller.php internal refs, the moved tests, and
one inline FQN in EndToEndTest). No class renames, no API changes.
PSR-4 sub-namespaces are derived from sub-directories, so composer.json
autoload map needs no edit.

Verified via the full quality pipeline (rector, pint, phpunit, phpstan).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

Then:

```bash
git log --oneline -1
git status --short
```
Expected: the commit shows up; working tree clean.

---

## Self-review notes

The author of this plan ran the following self-review against the spec:

1. **Spec coverage:**
   - Move 8 production files into Http/ and Catalog/ → Step 2.
   - Move 5 tests into mirroring directories → Step 3.
   - Update each moved file's `namespace` declaration → Step 4 (sed) + Steps 5, 6 confirm via the rewrites.
   - Update `use` imports in App.php (root) → Step 6.
   - Update `use` imports in Controller.php (moved + has new internal refs) → Step 5.
   - Update `use` imports in 5 moved test files → Steps 7, 8, 9, 10, 11.
   - Update inline FQN in EndToEndTest.php → Step 12.
   - Update inline FQN in PackagesJsonTest.php (4 `\RepAhead\ZipMeta`) → Step 10.
   - Update inline FQN in Controller.php (`\RepAhead\ZipMeta` × 2 and `\RepAhead\PackagesJsonResult`) → Step 5.
   - `composer dump-autoload` → Step 13.
   - Sanity grep → Step 14.
   - Quality pipeline gate → Steps 15–17.
   - Single coherent commit → Step 18.

2. **Placeholder scan:** Every step has concrete commands and concrete code blocks. No TBD/TODO. The two file rewrites (Controller, App) show the exact new top section.

3. **Type consistency:** Class names are unchanged. The only naming concern is the namespace — every reference in the plan uses the new namespace consistently (`RepAhead\Http\…`, `RepAhead\Catalog\…`, `RepAhead\Tests\Http\…`, `RepAhead\Tests\Catalog\…`).
