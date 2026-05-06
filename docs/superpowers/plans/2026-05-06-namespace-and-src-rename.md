# Composerd → RepAhead + src/ → app/ Rename Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rename the project's PHP namespace from `Composerd` to `RepAhead` and the production source folder from `src/` to `app/`, with no behavioural change.

**Architecture:** Single-commit mechanical refactor. `git mv` for the directory, three `sed` passes for the namespace and runtime-string replacements, manual edits for the handful of config files that reference the path or namespace by string. The existing quality pipeline (`composer rector` → `pint` → `test` → `stan`) verifies the rename caught every reference.

**Tech Stack:** No new dependencies. Uses BSD `sed` (macOS), `grep`, `git mv`, and the project's existing composer scripts.

**Spec:** `docs/superpowers/specs/2026-05-06-namespace-and-src-rename-design.md`

**Working directory:** `/Users/kl3tte/development/repahead`. We're on branch `feat/composer-server-v1`.

**Why one task with many steps:** the spec mandates a single commit to avoid leaving the repo in an unbuildable state mid-rename. The subagent executes all steps sequentially, then commits once at the end.

---

## File Structure

The rename touches these existing files (no new files are created, no files are deleted):

```
.                                # repo root unchanged
├── app/                         # was src/ — every PHP file inside renamed in namespace
│   ├── App.php
│   ├── Auth.php
│   ├── Cache.php
│   ├── Catalog.php
│   ├── CatalogEntry.php
│   ├── Config.php
│   ├── Controller.php
│   ├── PackagesJson.php
│   ├── SafeJsonStrategy.php
│   ├── StderrLogger.php
│   ├── Storage.php
│   ├── ZipMeta.php
│   └── ZipMetadata.php
├── tests/                       # name unchanged; namespaces inside renamed
│   ├── *.php                    # all 11 test files
│   └── Support/
│       ├── ThrowingFilesystem.php
│       └── ZipBuilder.php
├── public/
│   └── index.php                # `use Composerd\…` imports renamed
├── composer.json                # PSR-4 autoload map keys + values
├── phpunit.xml                  # source <directory>src</directory> → app
├── phpstan.neon                 # paths: - src → - app
├── rector.php                   # __DIR__ . '/src' → __DIR__ . '/app'
├── Dockerfile                   # COPY src ./src → COPY app ./app
└── CLAUDE.md                    # documentation reference lines
```

Files that **stay unchanged**:
- `docs/superpowers/specs/*.md`, `docs/superpowers/plans/*.md` — historical artifacts.
- `README.md` — does not reference the namespace or folder.
- `.dockerignore`, `.env.example`, `.gitignore`, `compose.yml`, `pint.json` — no `src/` or `Composerd` references.
- The `tests/`, `public/`, `cache/`, `zips/` directory names.

---

## Task 1: Perform the rename in a single commit

This is the only task. It has 19 steps; all of them must complete cleanly before the commit. If any verification step fails, fix the underlying cause before proceeding to the next step (do **not** commit a half-renamed tree).

**Files:**
- Move: `src/` → `app/`
- Modify (PHP): every file under `app/`, `tests/`, `public/`
- Modify (config): `composer.json`, `phpunit.xml`, `phpstan.neon`, `rector.php`, `Dockerfile`, `CLAUDE.md`

- [ ] **Step 1: Confirm starting state is clean**

```bash
git status --short
```
Expected: empty output. If anything is staged or unstaged, stop and surface that to the controller — the rename should land on a clean tree so the resulting diff is reviewable.

- [ ] **Step 2: Move the source directory**

```bash
git mv src app
```
Expected: no output. `git status --short` should now show 13 lines like `R  src/App.php -> app/App.php`.

- [ ] **Step 3: Update `composer.json` PSR-4 autoload map**

Edit `composer.json`. Replace this block:

```json
    "autoload": {
        "psr-4": { "Composerd\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "Composerd\\Tests\\": "tests/" }
    },
```

with:

```json
    "autoload": {
        "psr-4": { "RepAhead\\": "app/" }
    },
    "autoload-dev": {
        "psr-4": { "RepAhead\\Tests\\": "tests/" }
    },
```

- [ ] **Step 4: Bulk-rename `Composerd\…` references in PHP source**

This pass catches `use Composerd\X;`, `\Composerd\X` (FQN form, including `\Composerd\Tests\Support\…`), and bare `Composerd\X` in PHPDoc.

```bash
grep -rl --include="*.php" 'Composerd\\' app tests public | xargs sed -i '' 's|Composerd\\|RepAhead\\|g'
```

Expected: no error output. To confirm, the same grep should now return nothing for `Composerd\\`:

```bash
grep -rl --include="*.php" 'Composerd\\' app tests public
```
Expected: no output (no files match).

- [ ] **Step 5: Bulk-rename bare `namespace Composerd` declarations**

The previous pass only touched `Composerd\…` (with a trailing backslash). Bare `namespace Composerd;` (which appears in `app/CatalogEntry.php`, `app/Config.php`, `app/Controller.php`, etc.) needs its own pass:

```bash
grep -rl --include="*.php" 'namespace Composerd' app tests public | xargs sed -i '' 's|namespace Composerd|namespace RepAhead|g'
```
Expected: no error output.

```bash
grep -rl --include="*.php" 'namespace Composerd' app tests public
```
Expected: no output.

- [ ] **Step 6: Bulk-rename runtime temp-file prefixes**

```bash
grep -rl --include="*.php" 'composerd-' app tests | xargs sed -i '' 's|composerd-|repahead-|g'
```
Expected: no error output.

```bash
grep -rl --include="*.php" 'composerd-' app tests
```
Expected: no output.

- [ ] **Step 7: Final sanity grep — no `Composerd` or `composerd-` left in code**

```bash
grep -rEn --include="*.php" 'Composerd|composerd-' app tests public
```
Expected: no output. (If anything appears, read each match — there may be a stray reference the bulk passes missed.)

- [ ] **Step 8: Update `phpunit.xml` source-coverage path**

Edit `phpunit.xml`. Change:

```xml
  <source>
    <include>
      <directory>src</directory>
    </include>
  </source>
```

to:

```xml
  <source>
    <include>
      <directory>app</directory>
    </include>
  </source>
```

- [ ] **Step 9: Update `phpstan.neon` paths**

Edit `phpstan.neon`. Change:

```yaml
    paths:
        - src
        - tests
        - public
```

to:

```yaml
    paths:
        - app
        - tests
        - public
```

- [ ] **Step 10: Update `rector.php` paths**

Edit `rector.php`. In the `withPaths([...])` array, change `__DIR__ . '/src'` to `__DIR__ . '/app'`. The block goes from:

```php
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/public',
    ])
```

to:

```php
    ->withPaths([
        __DIR__ . '/app',
        __DIR__ . '/tests',
        __DIR__ . '/public',
    ])
```

- [ ] **Step 11: Update `Dockerfile` COPY directive**

Edit `Dockerfile`. Change:

```dockerfile
COPY --chown=www-data:www-data src ./src
```

to:

```dockerfile
COPY --chown=www-data:www-data app ./app
```

(The other `COPY` lines for `composer.json`, `composer.lock`, `public`, and `.env.example` stay unchanged.)

- [ ] **Step 12: Update `CLAUDE.md` documentation references**

Edit `CLAUDE.md`. Replace these three lines:

```markdown
- `phpstan.neon` — level 8 over `src/`, `tests/`, `public/`
```

becomes:

```markdown
- `phpstan.neon` — level 8 over `app/`, `tests/`, `public/`
```

And:

```markdown
- PSR-4 autoload: `Composerd\\` → `src/`, `Composerd\\Tests\\` → `tests/`.
- Tests sit beside the unit they cover (e.g. `src/Cache.php` ↔ `tests/CacheTest.php`).
```

becomes:

```markdown
- PSR-4 autoload: `RepAhead\\` → `app/`, `RepAhead\\Tests\\` → `tests/`.
- Tests sit beside the unit they cover (e.g. `app/Cache.php` ↔ `tests/CacheTest.php`).
```

- [ ] **Step 13: Final sanity grep on config files — no `Composerd` or `src/` left**

```bash
grep -nE 'Composerd|/src\b|"src"|<directory>src<' composer.json phpunit.xml phpstan.neon rector.php Dockerfile CLAUDE.md
```
Expected: no output. (The `/src\b` pattern intentionally avoids matching `/dist/...` paths which are unrelated. Adjust the pattern if a false positive appears.)

- [ ] **Step 14: Regenerate Composer autoload mappings**

```bash
composer dump-autoload
```
Expected: `Generated autoload files containing N classes` with no errors. The new `RepAhead\` PSR-4 entry is now registered.

- [ ] **Step 15: Verify with rector**

```bash
composer rector:dry
```
Expected: `[OK] Rector is done!` with `0 files would have been changed`. If rector wants to change anything, fix it (it's likely a follow-on type narrowing the rename made possible) and re-run.

- [ ] **Step 16: Verify with pint**

```bash
composer pint:test
```
Expected: `{"tool":"pint","result":"passed"}`. If pint flags anything, run `composer pint` to apply, then re-run `pint:test`.

- [ ] **Step 17: Verify with phpunit**

```bash
composer test
```
Expected: `OK (55 tests, 117 assertions)` (or whatever the current count is — must match the count from the last clean pipeline run). If any test fails with a class-not-found error, you missed a reference: re-run the sanity greps from steps 7 and 13.

- [ ] **Step 18: Verify with phpstan**

```bash
composer stan
```
Expected: `[OK] No errors`. If phpstan flags anything, the most likely cause is a stale FQN inside a PHPDoc tag, an `assert(... instanceof \Composerd\…)`, or a string-typed class reference that the bulk passes missed. Open the offending file and rename by hand, then re-run.

- [ ] **Step 19: Commit**

```bash
git add -A
git commit -m "$(cat <<'EOF'
refactor: rename namespace Composerd -> RepAhead and src/ -> app/

Mechanical rename per docs/superpowers/specs/2026-05-06-namespace-and-src-rename-design.md.
Single coherent commit so the repo never sits in an unbuildable state.

- All PHP source files under app/, tests/, public/: namespace Composerd[\Tests[\Support]]
  -> namespace RepAhead[\Tests[\Support]]; all use/FQN references updated to match.
- src/ -> app/ via git mv. Path references in composer.json, phpunit.xml,
  phpstan.neon, rector.php, Dockerfile, and CLAUDE.md updated to point at app/.
- Test temp-file prefixes (composerd-cache-, composerd-zip, etc.) renamed to
  repahead-* for grep consistency in failure logs.

Verified via the full quality pipeline (rector, pint, phpunit, phpstan).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

Verify the commit landed cleanly:

```bash
git log --oneline -1
git status --short
```
Expected: the commit shows up; working tree is clean.

---

## Self-review notes

The author of this plan ran the following self-review against the spec:

1. **Spec coverage** — every concrete change in the spec maps to one or more steps:
   - PHP namespace rename → Steps 4, 5
   - `src/` → `app/` rename → Step 2
   - `composer.json` autoload map → Step 3
   - `phpunit.xml`, `phpstan.neon`, `rector.php`, `Dockerfile`, `CLAUDE.md` path refs → Steps 8–12
   - Runtime temp-file prefixes → Step 6
   - Sanity grep for misses → Steps 7, 13
   - `composer dump-autoload` → Step 14
   - Quality pipeline gate → Steps 15–18
   - Single coherent commit → Step 19

2. **Placeholder scan** — no TBD/TODO; every command and code block is concrete; expected outputs are stated for every command.

3. **Type consistency** — n/a; this plan introduces no new types or method signatures, only renames existing ones uniformly.
