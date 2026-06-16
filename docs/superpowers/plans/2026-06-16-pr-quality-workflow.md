# PR Quality Workflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a GitHub Actions workflow that runs `rector:dry`, `pint:test`, `test`, and `stan` (in CLAUDE.md order) on every pull request and every push to `main`.

**Architecture:** One workflow file at `.github/workflows/quality.yml`. One job (`quality`) on `ubuntu-latest`. Sequential steps that shell out to the existing composer scripts — no new composer scripts, no auto-fix commits, no PHP version matrix.

**Tech Stack:** GitHub Actions, `actions/checkout@v4`, `shivammathur/setup-php@v2`, `actions/cache@v4`, Composer 2, PHP 8.5.

---

## File Structure

- **Create:** `.github/workflows/quality.yml` — the entire workflow
- No other files change. No new composer scripts, no edits to existing workflows.

The file is self-contained. It depends only on (a) composer scripts already in `composer.json` (`rector:dry`, `pint:test`, `test`, `stan`) and (b) `composer.lock` for cache keying.

---

## Task 1: Create the workflow skeleton

**Files:**
- Create: `.github/workflows/quality.yml`

- [ ] **Step 1: Create the file with name, triggers, and concurrency**

Create `.github/workflows/quality.yml` with this content (the rest of the file gets filled in by later tasks — the next task adds the `jobs:` section):

```yaml
name: Quality

on:
  pull_request:
  push:
    branches: [main]

concurrency:
  group: quality-${{ github.workflow }}-${{ github.ref }}
  cancel-in-progress: true
```

Notes for the engineer:
- `pull_request:` with no `types:` filter is the right default — GitHub fires it on `opened`, `synchronize`, and `reopened`, which is exactly what we want.
- `concurrency` with `cancel-in-progress: true` cancels the previous still-running run when a new commit lands on the same PR ref. Saves runner minutes. (The existing `cve-patch.yml` uses `cancel-in-progress: false` because it gates a release — different needs.)
- Do NOT add `workflow_dispatch:` — we decided against it in brainstorming.

- [ ] **Step 2: Validate the YAML parses**

Run:
```bash
python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/quality.yml'))"
```
Expected: exits 0 with no output.

(If `python3 -c "import yaml"` fails because PyYAML isn't installed, fall back to `ruby -e "require 'yaml'; YAML.load_file('.github/workflows/quality.yml')"` — Ruby is preinstalled on macOS and includes YAML in stdlib.)

- [ ] **Step 3: Do NOT commit yet**

The workflow has no jobs yet. GitHub will refuse to register a workflow with zero jobs. Commit after Task 3.

---

## Task 2: Add the job, checkout, PHP setup, and composer cache

**Files:**
- Modify: `.github/workflows/quality.yml` (append `jobs:` section)

- [ ] **Step 1: Append the job skeleton with setup steps**

Append to `.github/workflows/quality.yml`:

```yaml

jobs:
  quality:
    name: quality
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Set up PHP 8.5
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
          coverage: none
          tools: composer

      - name: Cache Composer downloads
        uses: actions/cache@v4
        with:
          path: ~/.composer/cache
          key: composer-${{ runner.os }}-${{ hashFiles('composer.lock') }}
          restore-keys: |
            composer-${{ runner.os }}-

      - name: Install dependencies
        run: composer install --prefer-dist --no-progress --no-interaction
```

Notes:
- `php-version: '8.5'` is quoted intentionally so YAML treats it as a string. Unquoted `8.5` parses as a float and `setup-php` would receive `8.5` vs `'8.5'` — the action handles both, but quoting is the documented form.
- `coverage: none` skips Xdebug/PCOV install. We don't measure coverage, and this saves ~10s of setup.
- `tools: composer` ensures Composer 2 is on PATH (it is by default on `ubuntu-latest`, but pinning via `setup-php` is the documented pattern).
- The cache path is `~/.composer/cache` — the **download** cache, not `vendor/`. Restoring `vendor/` directly is flaky across PHP minor versions and platform-specific binaries.
- `restore-keys:` lets us reuse an older cache when `composer.lock` changes — `composer install` only has to download the changed packages.

- [ ] **Step 2: Validate the YAML parses**

Run:
```bash
python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/quality.yml'))"
```
Expected: exits 0.

- [ ] **Step 3: Do NOT commit yet**

The job has setup but no quality steps. Commit after Task 3.

---

## Task 3: Add the four quality steps and commit

**Files:**
- Modify: `.github/workflows/quality.yml` (append the four `run:` steps)

- [ ] **Step 1: Append the quality steps**

Append to `.github/workflows/quality.yml`, indented at the same level as the other `steps:` entries (six spaces before `- name:`):

```yaml

      - name: Rector (check)
        run: composer rector:dry

      - name: Pint (check)
        run: composer pint:test

      - name: PHPUnit
        run: composer test

      - name: PHPStan (level 8)
        run: composer stan
```

Notes:
- Order is the exact CLAUDE.md order: rector → pint → test → stan. Don't change it.
- Default GitHub Actions semantics already stop the job on the first failing step. No `continue-on-error` anywhere.
- No `working-directory:` — the repo root is the default and is correct.
- Each step shells to a composer script that already exists in `composer.json` — verify with `grep -E '"(rector:dry|pint:test|test|stan)":' composer.json` and confirm all four print.

- [ ] **Step 2: Validate the full YAML one more time**

Run:
```bash
python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/quality.yml'))"
```
Expected: exits 0.

- [ ] **Step 3: Eyeball the full file**

Run:
```bash
cat .github/workflows/quality.yml
```
Expected output (verbatim — compare exactly):

```yaml
name: Quality

on:
  pull_request:
  push:
    branches: [main]

concurrency:
  group: quality-${{ github.workflow }}-${{ github.ref }}
  cancel-in-progress: true

jobs:
  quality:
    name: quality
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Set up PHP 8.5
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
          coverage: none
          tools: composer

      - name: Cache Composer downloads
        uses: actions/cache@v4
        with:
          path: ~/.composer/cache
          key: composer-${{ runner.os }}-${{ hashFiles('composer.lock') }}
          restore-keys: |
            composer-${{ runner.os }}-

      - name: Install dependencies
        run: composer install --prefer-dist --no-progress --no-interaction

      - name: Rector (check)
        run: composer rector:dry

      - name: Pint (check)
        run: composer pint:test

      - name: PHPUnit
        run: composer test

      - name: PHPStan (level 8)
        run: composer stan
```

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/quality.yml
git commit -m "Add PR quality workflow

Runs rector:dry, pint:test, test, stan in CLAUDE.md order on every PR
and every push to main."
```

---

## Task 4: Verify the pipeline still passes locally

This task catches a CI regression before it lands: if the local pipeline doesn't pass right now, the new workflow's first run will fail and that failure won't be about the workflow — it'll be about the working tree. Confirm a clean baseline before pushing.

**Files:** none modified

- [ ] **Step 1: Run the same four commands the workflow will run**

Run, stopping on the first failure (`set -e` semantics via `&&`):

```bash
composer rector:dry && composer pint:test && composer test && composer stan
```

Expected: all four exit 0. The output ends with PHPStan's `[OK] No errors`.

If any step fails, fix it on the current branch with a separate commit before continuing — do NOT modify the workflow to paper over it.

- [ ] **Step 2: Push and open/refresh the PR**

```bash
git push
```

(If this is a fresh branch with no upstream, the command will fail with a hint — use the `git push --set-upstream origin <branch>` it suggests.)

Then watch the new `Quality` workflow in the Actions tab. The first run on this PR is the live acceptance test.

---

## Acceptance criteria (from the spec — verify on the live PR)

- A PR that breaks rector fails the `Rector (check)` step; later steps don't run.
- A PR with a failing test fails at `PHPUnit`; PHPStan does not run.
- A PR that passes all four locally passes all four in CI.
- A push to `main` runs the same four checks and reports `Quality / quality` on the commit.
- Re-running an unchanged PR finishes faster than the first run (composer cache restored — look for `Cache restored from key:` in the cache step's log).
- No bot commits land on any branch.

---

## Self-review notes (for the executor)

- The plan creates exactly one file. No new composer scripts, no edits to existing workflows or composer.json — the spec's "thin shell" promise is preserved.
- All four quality commands the workflow runs already exist in `composer.json` (`rector:dry`, `pint:test`, `test`, `stan`).
- The check name visible to branch protection is `Quality / quality` (workflow name / job name) — matches the spec.
- Task 4 doesn't modify the workflow; it's the local pre-push gate. Skip it only if you've already run the quality pipeline since your last edit.
