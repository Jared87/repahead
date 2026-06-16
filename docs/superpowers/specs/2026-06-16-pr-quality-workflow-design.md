# PR Quality Workflow Design

A GitHub Actions workflow that runs the project's quality pipeline
(`rector`, `pint`, `test`, `stan`) on every pull request and every push to
`main`, so the merge button never lights up green on code that fails the
pipeline documented in `CLAUDE.md`.

## Goals

- Mirror the local quality pipeline 1:1 — same tools, same order, same
  invocations. "Passes locally" and "passes in CI" must mean the same thing.
- Fail fast on the most likely failure (refactor and style drift) before
  spending time on the slower checks (tests, static analysis).
- Add no new composer scripts. The workflow is a thin shell around the
  existing `composer rector:dry`, `composer pint:test`, `composer test`,
  `composer stan` commands.
- Keep CI side-effect-free. No auto-commits, no auto-fixes, no pushes.

## Non-goals

- Code coverage. We don't measure it today.
- Dependency or container CVE scanning. `cve-patch.yml` covers image-level
  scanning; adding another scanner here would duplicate that.
- PHP version matrix. `composer.json` pins `"php": ">=8.5"` and that is the
  version we run locally; testing on older versions would be testing a
  configuration the project doesn't support.
- Branch protection rules. Those live in GitHub repo settings, not in this
  repo. The workflow defines a check; the admin can wire it to `main`
  once it has run green.
- Auto-fix commits from the rector/pint steps.

## Triggers

```yaml
on:
  pull_request:
  push:
    branches: [main]

concurrency:
  group: quality-${{ github.workflow }}-${{ github.ref }}
  cancel-in-progress: true
```

`pull_request` covers every PR event GitHub fires that matters for CI
(opened, synchronize, reopened). `push` to `main` re-verifies merge commits
so the default branch always has a current green signal.

`cancel-in-progress: true` lets a second push to a PR cancel the
still-running first job. The earlier `cve-patch.yml` workflow sets this to
`false`, but that workflow gates a release and must not be interrupted —
the quality workflow has no such constraint.

## Layout

One job, named `quality`, on `ubuntu-latest`. Sequential steps in the
CLAUDE.md order:

1. `actions/checkout@v4`
2. `shivammathur/setup-php@v2` with `php-version: 8.5`, `coverage: none`,
   `tools: composer`
3. `actions/cache@v4` for `~/.composer/cache`, keyed on
   `${{ hashFiles('composer.lock') }}`
4. `composer install --prefer-dist --no-progress --no-interaction`
5. `composer rector:dry`
6. `composer pint:test`
7. `composer test`
8. `composer stan`

`coverage: none` skips Xdebug/PCOV installation. We don't need them, and
they slow PHP startup on every subsequent command.

The composer cache restores `~/.composer/cache` (download cache), not
`vendor/`. Restoring `vendor/` directly tends to be flaky across PHP minor
versions and platform-specific binary packages; restoring the download
cache lets `composer install` run fast without that risk.

## Why sequential, not parallel

The four tools are run in one job, in order, stopping on the first
failure. Three reasons:

- The CLAUDE.md ordering is deliberate: rector and pint both reformat, so
  style runs after refactor; tests run before stan because real bugs
  usually fail a test before they fail type analysis. Reproducing that
  order in CI keeps the local and CI signals identical.
- One job pays the setup cost (checkout, PHP, composer install) once. Four
  parallel jobs would pay it four times, eroding the wall-clock win on a
  small codebase.
- When a PR is broken, fail-fast on the most-likely-to-fail step is more
  useful than "all four failures shown side by side." The author goes to
  fix one thing at a time anyway.

## File layout

- `.github/workflows/quality.yml` — the new workflow
- Workflow `name:` — `Quality`
- Job key — `quality`
- Status check name visible to branch protection — `Quality / quality`

No existing workflow files change.

## Enforcement model

Rector and Pint run in check-only mode:

- `composer rector:dry` already maps to `rector --dry-run`. Fails with a
  non-zero exit when changes would be applied.
- `composer pint:test` already maps to `pint --test`. Same semantics.

When CI fails on either, the author re-runs `composer rector && composer
pint` locally and commits the result. CI itself never writes to the
working tree.

## Out of scope but worth noting

Once the workflow has run green on a PR or push to `main` once, the repo
admin can add `Quality / quality` as a required status check on `main` via
GitHub repo settings. That step is not part of this change because branch
protection rules are not stored in the repository.

## Acceptance criteria

- A new PR that touches PHP code and breaks rector fails the `Rector
  (check)` step; the other quality steps do not run.
- A PR that passes rector and pint but introduces a failing test fails at
  the `PHPUnit` step; PHPStan does not run.
- A PR that passes all four locally passes all four in CI.
- A push to `main` runs the same four checks and reports `Quality /
  quality` on the commit.
- Re-running an unchanged PR finishes faster than the first run, because
  the composer cache is restored.
- No bot commits land on any branch.
