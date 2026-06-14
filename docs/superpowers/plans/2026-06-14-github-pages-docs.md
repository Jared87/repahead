# GitHub Pages Documentation Site — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Publish an MkDocs Material documentation site to `https://tredmann.github.io/repahead/`, deployed via GitHub Actions, and trim the README to a quick-start landing.

**Architecture:** MkDocs Material reads `docs/*.md` and builds a static site. `docs/adr/` and `docs/superpowers/` are excluded from the build via MkDocs' built-in `exclude_docs`. A two-job GitHub Actions workflow (`build` → `deploy`) uses the official Pages artifact flow — no `gh-pages` branch. README links to the site for full reference material.

**Tech Stack:** MkDocs Material, Python 3.12, GitHub Actions (`actions/upload-pages-artifact@v3`, `actions/deploy-pages@v4`), GitHub Pages.

**Spec:** `docs/superpowers/specs/2026-06-14-github-pages-docs-design.md`

**Prerequisites for the executing engineer:**
- Python 3.12+ available locally (used for `mkdocs build --strict` checks).
- The working tree is currently on branch `feature/docs`.
- Task 1 creates a virtualenv at `/tmp/repahead-docs-venv`. Tasks 2–8 and 11 reuse it in their verify steps. If you start a new shell or session and the venv is missing (e.g. after a reboot), redo Task 1 Steps 1–2 before running any later verify step.
- One factual gotcha: the existing `README.md` claims path "wins" over `composer.json`'s `name` field on mismatch. The actual code (`app/Catalog/PackagesJson.php:53`) **rejects** the Release on mismatch. CONTEXT.md is the correct authority. The new docs must reflect the code; the README's wrong line goes away when it is trimmed in Task 11.

---

## Task 1: Scaffold the MkDocs site

Set up the project skeleton: MkDocs config, pinned dependency, and seven empty pages that the nav references. After this task, `mkdocs build --strict` must succeed.

**Files:**
- Create: `mkdocs.yml`
- Create: `docs/requirements.txt`
- Create: `docs/index.md`
- Create: `docs/installation.md`
- Create: `docs/configuration.md`
- Create: `docs/publishing.md`
- Create: `docs/consuming.md`
- Create: `docs/endpoints.md`
- Create: `docs/troubleshooting.md`

- [ ] **Step 1: Install MkDocs Material in a throwaway venv and capture the current stable version**

```bash
python3 -m venv /tmp/repahead-docs-venv
source /tmp/repahead-docs-venv/bin/activate
pip install --upgrade pip
pip install mkdocs-material
pip show mkdocs-material | grep '^Version:' | awk '{print $2}'
```

Note the version printed (e.g. `9.5.34`). Use it in Step 2.

- [ ] **Step 2: Create `docs/requirements.txt`**

```
mkdocs-material==<VERSION_FROM_STEP_1>
```

Replace `<VERSION_FROM_STEP_1>` with the exact version from Step 1 (no `^`, `~`, or range — strict pin).

- [ ] **Step 3: Create `mkdocs.yml`**

```yaml
site_name: repahead
site_description: A private Composer (Packagist-compatible) repository server.
site_url: https://tredmann.github.io/repahead/
repo_url: https://github.com/tredmann/repahead
repo_name: tredmann/repahead
edit_uri: edit/main/docs/

theme:
  name: material
  features:
    - navigation.tabs
    - navigation.sections
    - content.code.copy
    - content.action.edit
    - search.suggest
    - search.highlight
  palette:
    - media: "(prefers-color-scheme: light)"
      scheme: default
      toggle:
        icon: material/brightness-7
        name: Switch to dark mode
    - media: "(prefers-color-scheme: dark)"
      scheme: slate
      toggle:
        icon: material/brightness-4
        name: Switch to light mode

exclude_docs: |
  adr/
  superpowers/

nav:
  - Home: index.md
  - Installation: installation.md
  - Configuration: configuration.md
  - Publishing: publishing.md
  - Consuming: consuming.md
  - Endpoints: endpoints.md
  - Troubleshooting: troubleshooting.md

markdown_extensions:
  - admonition
  - attr_list
  - tables
  - pymdownx.highlight
  - pymdownx.superfences
  - pymdownx.tabbed:
      alternate_style: true
```

- [ ] **Step 4: Create the seven stub pages**

Each stub is just an H1 — content arrives in later tasks. This lets `mkdocs build --strict` succeed end-to-end before any content is written.

`docs/index.md`:

```markdown
# repahead
```

`docs/installation.md`:

```markdown
# Installation
```

`docs/configuration.md`:

```markdown
# Configuration
```

`docs/publishing.md`:

```markdown
# Publishing
```

`docs/consuming.md`:

```markdown
# Consuming
```

`docs/endpoints.md`:

```markdown
# Endpoints
```

`docs/troubleshooting.md`:

```markdown
# Troubleshooting
```

- [ ] **Step 5: Verify the build is strict-clean**

```bash
source /tmp/repahead-docs-venv/bin/activate
pip install -r docs/requirements.txt
mkdocs build --strict
```

Expected: `INFO    -  Documentation built in X.XX seconds` with no `WARNING` lines. `--strict` turns warnings into errors, so the run must exit 0.

Also confirm `docs/adr/` and `docs/superpowers/` did NOT end up in `site/`:

```bash
ls site/ | grep -E 'adr|superpowers' && echo "FAIL: excluded folders leaked into build" || echo "OK: exclusion works"
```

Expected: `OK: exclusion works`.

- [ ] **Step 6: Clean up build artifact and ignore it**

```bash
rm -rf site/
```

Confirm `.gitignore` already ignores `site/` (the MkDocs build output dir). If it doesn't, append `site/` to `.gitignore`. Check with:

```bash
grep -E '^site/?$' .gitignore || echo "site/" >> .gitignore
```

- [ ] **Step 7: Commit**

```bash
git add mkdocs.yml docs/requirements.txt docs/index.md docs/installation.md docs/configuration.md docs/publishing.md docs/consuming.md docs/endpoints.md docs/troubleshooting.md .gitignore
git commit -m "Scaffold MkDocs Material documentation site"
```

---

## Task 2: Write the landing page (`docs/index.md`)

**Files:**
- Modify: `docs/index.md`

- [ ] **Step 1: Replace the stub with landing content**

Overwrite `docs/index.md` with:

````markdown
# repahead

A small PHP service that exposes a private [Composer](https://getcomposer.org/) (Packagist-compatible) repository. Publishers drop Release ZIPs into Storage; the service builds and serves the Index over HTTP with basic auth.

Storage is pluggable via [Flysystem](https://flysystem.thephpleague.com/): local disk or S3.

## In 30 seconds

```bash
cp .env.example .env  # set AUTH_PASS
AUTH_PASS=$(grep AUTH_PASS .env | cut -d= -f2) docker compose up -d --build
```

Drop a Release ZIP and refresh the Index:

```bash
docker compose cp ./acme-billing-1.2.0.zip composer:/var/www/html/zips/acme/billing/1.2.0.zip
curl -u ci:secret -X POST http://localhost:8080/rebuild
```

## Where to next

- **[Installation](installation.md)** — Docker or local PHP setup.
- **[Configuration](configuration.md)** — Environment variables, storage backends, auth.
- **[Publishing](publishing.md)** — How to add Releases.
- **[Consuming](consuming.md)** — Use the repository from a Composer project.
- **[Endpoints](endpoints.md)** — Full HTTP reference.
- **[Troubleshooting](troubleshooting.md)** — Common issues.
````

- [ ] **Step 2: Verify the build is still strict-clean**

```bash
source /tmp/repahead-docs-venv/bin/activate
mkdocs build --strict
rm -rf site/
```

Expected: build succeeds with no warnings.

- [ ] **Step 3: Commit**

```bash
git add docs/index.md
git commit -m "Write landing page for docs site"
```

---

## Task 3: Write the installation page (`docs/installation.md`)

**Files:**
- Modify: `docs/installation.md`

- [ ] **Step 1: Replace the stub with installation content**

Overwrite `docs/installation.md` with:

````markdown
# Installation

## Docker (recommended)

repahead ships as a Docker image: [`tredmann/repahead`](https://hub.docker.com/r/tredmann/repahead).

### Using docker compose

```bash
cp .env.example .env
# edit AUTH_PASS at minimum
AUTH_PASS=$(grep AUTH_PASS .env | cut -d= -f2) docker compose up -d --build
```

The service listens on `http://localhost:8080`. The image bakes in `HEALTHCHECK curl -sf http://localhost:8080/health` every 30 seconds.

### Using `docker run`

Minimal — only `AUTH_PASS` is required:

```bash
docker run -d -p 8080:8080 -e AUTH_PASS=secret tredmann/repahead
```

Production — set `APP_BASE_URL` so dist links resolve correctly, and mount a volume for ZIPs:

```bash
docker run -d \
  -p 8080:8080 \
  -e AUTH_PASS=secret \
  -e APP_BASE_URL=https://composer.your-domain.com \
  -v /path/to/zips:/var/www/html/zips \
  tredmann/repahead
```

## Local PHP

Requires PHP 8.5+ with extensions `fileinfo`, `json`, `zip`, plus [Composer](https://getcomposer.org/).

```bash
composer install
cp .env.example .env
php -S 127.0.0.1:8080 -t public
```

See [Configuration](configuration.md) for the full environment-variable reference.
````

- [ ] **Step 2: Verify the build is still strict-clean**

```bash
source /tmp/repahead-docs-venv/bin/activate
mkdocs build --strict
rm -rf site/
```

Expected: build succeeds with no warnings.

- [ ] **Step 3: Commit**

```bash
git add docs/installation.md
git commit -m "Write installation page"
```

---

## Task 4: Write the configuration page (`docs/configuration.md`)

**Files:**
- Modify: `docs/configuration.md`

- [ ] **Step 1: Replace the stub with configuration content**

Overwrite `docs/configuration.md` with:

````markdown
# Configuration

repahead is configured entirely through environment variables. In Docker, pass them via `docker run -e` or `compose.yml`. In a local install, populate `.env` (loaded automatically at boot).

## Environment variable reference

| Variable | Default | Description |
|----------|---------|-------------|
| `AUTH_PASS` | — | **Required.** HTTP basic auth password. |
| `AUTH_USER` | `ci` | HTTP basic auth username. |
| `APP_BASE_URL` | `http://localhost:8080` | Public base URL; used in `packages.json` dist download links. Set this in production so Composer clients fetch from the right host. |
| `STORAGE_DSN` | `local:/var/www/html/zips` | Storage backend. See [Storage backends](#storage-backends). |
| `CACHE_DIR` | `/var/www/html/cache` | Directory for the `packages.json` cache and hash files. Always local, regardless of the Storage backend. |
| `LISTING_TTL_SECONDS` | `30` | Seconds before Storage is re-listed. `0` disables the TTL (Storage is listed on every request). See [Cache behavior](#cache-behavior). |
| `AWS_ACCESS_KEY_ID` | — | S3 only. Explicit AWS access key ID. Omit to use ambient credentials. |
| `AWS_SECRET_ACCESS_KEY` | — | S3 only. Must be set together with `AWS_ACCESS_KEY_ID` or not at all. |
| `AWS_REGION` | — | S3 only. AWS region (e.g. `eu-central-1`). Required. |
| `SERVER_NAME` | `:8080` | Listen address and port (base image). |
| `AUTOMATIC_HTTPS` | `off` | Auto-HTTPS via FrankenPHP/Caddy. Keep `off` behind a reverse proxy. |
| `PHP_OPCACHE_ENABLE` | `1` | Enable PHP OPcache. |

## Storage backends

`STORAGE_DSN` selects the backend.

### Local disk

```
STORAGE_DSN=local:./zips
```

Relative paths resolve against the project root. Use absolute paths (e.g. `local:/var/lib/repahead/zips`) when running outside the repo.

### S3 — explicit credentials

For local development or non-AWS hosting:

```
STORAGE_DSN=s3:my-bucket/composer/zips
AWS_ACCESS_KEY_ID=AKIA...
AWS_SECRET_ACCESS_KEY=...
AWS_REGION=eu-central-1
```

### S3 — ambient credentials

For EC2 instance profiles, ECS task roles, or Lambda execution roles:

```
STORAGE_DSN=s3:my-bucket/composer/zips
AWS_REGION=eu-central-1
```

Omit `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` entirely — the AWS SDK resolves credentials automatically from the runtime environment. `AWS_REGION` must still be set explicitly on EC2; Lambda sets it automatically, and ECS sets `AWS_DEFAULT_REGION`.

### S3 IAM policy

repahead only reads from S3 (list + download). The minimum bucket policy:

```json
{
  "Effect": "Allow",
  "Action": ["s3:ListBucket", "s3:GetObject"],
  "Resource": [
    "arn:aws:s3:::my-bucket",
    "arn:aws:s3:::my-bucket/composer/zips/*"
  ]
}
```

## Cache behavior

repahead uses two cache tiers:

1. **Listing TTL** (`LISTING_TTL_SECONDS`) — how often Storage is re-listed. Defaults to 30 seconds.
2. **Listing Fingerprint** — even after a fresh listing, the cached Index is reused unchanged if the Release set has not changed (SHA-256 match).

Set `LISTING_TTL_SECONDS=0` to disable the TTL and always re-list. Use `POST /rebuild` to force an immediate rebuild without waiting for the TTL.

See [ADR-0001 — Two-tier cache invalidation](https://github.com/tredmann/repahead/blob/main/docs/adr/0001-two-tier-cache-invalidation.md) for the rationale.
````

- [ ] **Step 2: Verify the build is still strict-clean**

```bash
source /tmp/repahead-docs-venv/bin/activate
mkdocs build --strict
rm -rf site/
```

Expected: build succeeds with no warnings.

- [ ] **Step 3: Commit**

```bash
git add docs/configuration.md
git commit -m "Write configuration reference page"
```

---

## Task 5: Write the publishing page (`docs/publishing.md`)

**Files:**
- Modify: `docs/publishing.md`

- [ ] **Step 1: Replace the stub with publishing content**

Note: this page describes the *actual* behavior — a `name`/path mismatch **rejects** the Release. Do not be tempted to copy the README's incorrect wording.

Overwrite `docs/publishing.md` with:

````markdown
# Publishing

A Release is one ZIP file for a specific version of a Package. To publish, place the ZIP into Storage at the right path — repahead picks it up on the next listing.

repahead never writes to Storage. Publishing happens entirely out of band, via whatever copy mechanism fits the backend.

## Folder layout

```
zips/
  vendor/
    package/
      1.0.0.zip
      1.1.0.zip
      2.0.0.zip
```

Rules:

- The folder path (`vendor/package`) determines the Package identity.
- The filename (without `.zip`) determines the Release version.
- The `composer.json` inside the ZIP is the source for `require`, `autoload`, etc.
- If `composer.json`'s `name` is present and disagrees with the folder path, **the Release is rejected** and a warning is logged. Either omit the `name` field or make it match the folder path.

## Publishing to local Storage

In Docker:

```bash
docker compose cp ./acme-billing-1.2.0.zip composer:/var/www/html/zips/acme/billing/1.2.0.zip
```

On a bare-metal install, just `cp` (or `scp`) the file to the configured `STORAGE_DSN` path.

## Publishing to S3

Use any S3-compatible client:

```bash
aws s3 cp ./acme-billing-1.2.0.zip s3://my-bucket/composer/zips/acme/billing/1.2.0.zip
```

The same `vendor/package/version.zip` layout applies.

## Refreshing the Index

By default, repahead re-lists Storage every `LISTING_TTL_SECONDS` seconds (default: 30). To make a newly published Release immediately available, force a rebuild:

```bash
curl -u ci:secret -X POST https://composer.your-domain.com/rebuild
```

Response:

```json
{
  "packages": 12,
  "versions": 47,
  "skipped": 0,
  "duration_ms": 84
}
```

`skipped` counts Rejected Releases — corrupt ZIPs, missing `composer.json`, malformed JSON, or `name`-vs-path mismatches. Causes are logged to stderr.
````

- [ ] **Step 2: Verify the build is still strict-clean**

```bash
source /tmp/repahead-docs-venv/bin/activate
mkdocs build --strict
rm -rf site/
```

Expected: build succeeds with no warnings.

- [ ] **Step 3: Commit**

```bash
git add docs/publishing.md
git commit -m "Write publishing guide"
```

---

## Task 6: Write the consuming page (`docs/consuming.md`)

**Files:**
- Modify: `docs/consuming.md`

- [ ] **Step 1: Replace the stub with consuming content**

Overwrite `docs/consuming.md` with:

````markdown
# Consuming

Use repahead as a Composer repository from any project.

## Configure the consuming project

In the project that needs the private packages:

```bash
composer config repositories.private composer https://composer.your-domain.com
composer config http-basic.composer.your-domain.com ci <password>
composer require acme/billing:^1.0
```

The first command adds a `repositories` entry to `composer.json`:

```json
{
  "repositories": {
    "private": {
      "type": "composer",
      "url": "https://composer.your-domain.com"
    }
  }
}
```

The second stores the basic-auth credentials in `auth.json` (which Composer keeps separate from `composer.json` so credentials never end up in version control).

## Authentication in CI

For CI environments, prefer an env var over a checked-in `auth.json`:

```bash
export COMPOSER_AUTH='{"http-basic":{"composer.your-domain.com":{"username":"ci","password":"'"$COMPOSER_PASS"'"}}}'
composer install
```

This lets you rotate the password through your CI secret store without touching the repository.

## Troubleshooting first install

If `composer require` fails:

- **401 Unauthorized** — confirm the username / password match `AUTH_USER` / `AUTH_PASS` on the server. See [Troubleshooting → 401 Unauthorized](troubleshooting.md#401-unauthorized).
- **Package not found** — confirm the ZIP is at the right path in Storage and call `POST /rebuild` if the TTL has not expired. See [Publishing](publishing.md).
````

- [ ] **Step 2: Verify the build is still strict-clean**

```bash
source /tmp/repahead-docs-venv/bin/activate
mkdocs build --strict
rm -rf site/
```

Expected: build succeeds with no warnings.

- [ ] **Step 3: Commit**

```bash
git add docs/consuming.md
git commit -m "Write consumer setup guide"
```

---

## Task 7: Write the endpoints page (`docs/endpoints.md`)

**Files:**
- Modify: `docs/endpoints.md`

- [ ] **Step 1: Replace the stub with endpoints content**

Overwrite `docs/endpoints.md` with:

````markdown
# Endpoints

repahead exposes four HTTP endpoints. All except `/health` require HTTP basic auth (`AUTH_USER` / `AUTH_PASS`).

| Method | Route | Auth | Purpose |
|--------|-------|------|---------|
| `GET` | `/health` | none | Storage liveness probe |
| `GET` | `/packages.json` | basic | The Composer repository Index (cached) |
| `GET` | `/dist/{vendor}/{package}/{version}.zip` | basic | Stream a Release ZIP |
| `POST` | `/rebuild` | basic | Force an Index rebuild |

## `GET /health`

Liveness probe. Returns `200` with `{"status":"ok"}` if Storage is reachable, `503` otherwise.

Intentionally unauthenticated so Docker `HEALTHCHECK` and load-balancer probes work without credentials. The Dockerfile bakes in `curl -sf http://localhost:8080/health` every 30 seconds.

```json
{
  "status": "ok"
}
```

## `GET /packages.json`

The Composer repository Index. Composer clients fetch this first; everything else follows from it.

The response is the cached `packages.json` document. The cache is served until either `LISTING_TTL_SECONDS` expires or the Listing Fingerprint changes. See [Configuration → Cache behavior](configuration.md#cache-behavior).

## `GET /dist/{vendor}/{package}/{version}.zip`

Streams a Release ZIP straight from Storage.

The URL appears in each Release's `dist.url` field in the Index — Composer follows it during `composer install`. Do not construct these URLs by hand.

## `POST /rebuild`

Forces an immediate Index rebuild. Use after publishing a new Release to skip the TTL wait.

```json
{
  "packages": 12,
  "versions": 47,
  "skipped": 0,
  "duration_ms": 84
}
```

| Field | Meaning |
|-------|---------|
| `packages` | Count of distinct Packages in the Index |
| `versions` | Total Release count across all Packages |
| `skipped` | Count of Rejected Releases (corrupt ZIPs, missing `composer.json`, name/path mismatch) |
| `duration_ms` | Wall-clock time of the rebuild |

`skipped > 0` is not an error response — the rebuild still succeeded with the remaining Releases. Causes for each skipped Release are logged to stderr.
````

- [ ] **Step 2: Verify the build is still strict-clean**

```bash
source /tmp/repahead-docs-venv/bin/activate
mkdocs build --strict
rm -rf site/
```

Expected: build succeeds with no warnings.

- [ ] **Step 3: Commit**

```bash
git add docs/endpoints.md
git commit -m "Write endpoints reference page"
```

---

## Task 8: Write the troubleshooting page (`docs/troubleshooting.md`)

**Files:**
- Modify: `docs/troubleshooting.md`

- [ ] **Step 1: Replace the stub with troubleshooting content**

Overwrite `docs/troubleshooting.md` with:

````markdown
# Troubleshooting

## 401 Unauthorized

A request to `/packages.json`, `/dist/...`, or `/rebuild` returns 401.

- **Wrong credentials.** Confirm the username matches `AUTH_USER` (defaults to `ci`) and the password matches `AUTH_PASS`. Both are set as env vars on the server.
- **No credentials sent.** Composer reads basic-auth from `auth.json` or `COMPOSER_AUTH`. See [Consuming → Authentication in CI](consuming.md#authentication-in-ci).
- **Hitting `/health` by mistake.** Only `/health` is unauthenticated; everything else requires basic auth.

## Index seems stale

`POST /rebuild` returns the right counts but `/packages.json` still shows the old set.

- The Index is cached. Wait `LISTING_TTL_SECONDS` for the TTL to expire, **or** explicitly force a rebuild with `POST /rebuild`.
- After `POST /rebuild`, the new Index is served immediately — if you are still seeing the old one, check that you are hitting the right server (e.g. via `APP_BASE_URL`) and not a CDN or reverse-proxy cache.

## `/health` returns 503

Storage is unreachable.

- **Local backend** — confirm the configured path exists and is readable by the process running PHP.
- **S3 backend** — confirm IAM credentials work, the bucket name in `STORAGE_DSN` is correct, and the region in `AWS_REGION` matches the bucket's region.

## A Release is not appearing in the Index

The ZIP is in Storage but does not show up in `/packages.json` after a rebuild.

Check the `skipped` count in `POST /rebuild`'s response. If it is non-zero, your Release was Rejected. Common causes:

- The ZIP is missing `composer.json`.
- The ZIP's `composer.json` is malformed JSON.
- The `name` field in `composer.json` is present and disagrees with the folder path. Either omit the field or make it match the folder.
- The ZIP itself is corrupt or unreadable.

Each rejection is logged to stderr with the cause and the offending path.
````

- [ ] **Step 2: Verify the build is still strict-clean**

```bash
source /tmp/repahead-docs-venv/bin/activate
mkdocs build --strict
rm -rf site/
```

Expected: build succeeds with no warnings.

- [ ] **Step 3: Commit**

```bash
git add docs/troubleshooting.md
git commit -m "Write troubleshooting page"
```

---

## Task 9: Add the GitHub Actions build & deploy workflow

**Files:**
- Create: `.github/workflows/docs.yml`

- [ ] **Step 1: Create `.github/workflows/docs.yml`**

```yaml
name: Docs

on:
  push:
    branches: [main]
    paths:
      - 'docs/**'
      - 'mkdocs.yml'
      - '.github/workflows/docs.yml'
  workflow_dispatch:

permissions:
  contents: read
  pages: write
  id-token: write

concurrency:
  group: pages
  cancel-in-progress: false

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: actions/setup-python@v5
        with:
          python-version: '3.12'

      - run: pip install -r docs/requirements.txt

      - run: mkdocs build --strict

      - uses: actions/upload-pages-artifact@v3
        with:
          path: site/

  deploy:
    needs: build
    runs-on: ubuntu-latest
    environment:
      name: github-pages
      url: ${{ steps.deployment.outputs.page_url }}
    steps:
      - uses: actions/deploy-pages@v4
        id: deployment
```

- [ ] **Step 2: Lint the YAML syntactically**

Confirm the file parses as valid YAML before committing:

```bash
python3 -c "import yaml, sys; yaml.safe_load(open('.github/workflows/docs.yml'))" && echo "OK: valid YAML"
```

Expected: `OK: valid YAML`.

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/docs.yml
git commit -m "Add GitHub Actions workflow to build and deploy docs site"
```

---

## Task 10: Add the `composer docs` script

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Add a `docs` entry to the `scripts` block**

Open `composer.json`. The current `scripts` block looks like:

```json
"scripts": {
    "test": "phpunit",
    "rector": "rector",
    "rector:dry": "rector --dry-run",
    "stan": "phpstan analyse --memory-limit=512M",
    "pint": "pint",
    "pint:test": "pint --test"
}
```

Replace it with:

```json
"scripts": {
    "test": "phpunit",
    "rector": "rector",
    "rector:dry": "rector --dry-run",
    "stan": "phpstan analyse --memory-limit=512M",
    "pint": "pint",
    "pint:test": "pint --test",
    "docs": "pip install -r docs/requirements.txt && mkdocs serve"
}
```

(Add a trailing comma after `"pint:test": "pint --test"` and append the new `"docs"` line — Composer scripts are a JSON object, so order does not matter functionally but appending keeps the diff small.)

- [ ] **Step 2: Validate `composer.json`**

```bash
composer validate --no-check-publish
```

Expected: `./composer.json is valid` (the `--no-check-publish` flag suppresses warnings about license/version that are irrelevant for a `type: project` package).

- [ ] **Step 3: Commit**

```bash
git add composer.json
git commit -m "Add composer docs script for local site preview"
```

---

## Task 11: Trim the README

The README is the last to change so any earlier doc link or asset is already on the branch.

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Overwrite `README.md` with the trimmed version**

Replace the entire file contents with:

````markdown
# Private Composer Server

A small PHP service that exposes a private Composer (Packagist-compatible) repository. Publishers drop Release ZIPs into Storage; the service builds and serves the Index. Storage is pluggable via Flysystem (local disk or S3).

**Full documentation:** <https://tredmann.github.io/repahead/>

## Quick start (Docker)

```bash
cp .env.example .env
# edit AUTH_PASS at minimum
AUTH_PASS=$(grep AUTH_PASS .env | cut -d= -f2) docker compose up -d --build
```

The service listens on `http://localhost:8080`.

See the [installation guide](https://tredmann.github.io/repahead/installation/) for `docker run`, local PHP setup, and production configuration.

## Working on the docs

Local preview at <http://127.0.0.1:8000> with live reload (requires Python 3.12+):

```bash
composer docs
```

## For contributors

- Domain glossary: [`CONTEXT.md`](CONTEXT.md)
- Architecture decisions: [`docs/adr/`](docs/adr/)
- Full design spec: [`docs/superpowers/specs/2026-05-06-composer-server-design.md`](docs/superpowers/specs/2026-05-06-composer-server-design.md)
````

- [ ] **Step 2: Sanity-check that nothing in the build references README sections that no longer exist**

```bash
source /tmp/repahead-docs-venv/bin/activate
mkdocs build --strict
rm -rf site/
```

Expected: build still passes (the docs site does not link into the README, but this catches any oversight).

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "Trim README to quick-start landing pointing at docs site"
```

---

## Task 12: Post-merge one-time GitHub setup (human action required)

This task cannot be performed by an executing agent — it requires clicking through repository settings on github.com. Surface it to the operator before declaring the work complete.

**Files:** none

- [ ] **Step 1: Open a PR from `feature/docs` to `main` and merge it**

```bash
git push -u origin feature/docs
gh pr create --title "Add MkDocs documentation site" --body "$(cat <<'EOF'
## Summary
- Adds MkDocs Material documentation site published at https://tredmann.github.io/repahead/
- Deployed via GitHub Actions on every push to main that touches docs/, mkdocs.yml, or the workflow itself
- Trims README to quick-start + pointer to the site

## Test plan
- [ ] Confirm `composer docs` serves the site at http://127.0.0.1:8000 locally
- [ ] After merge, enable Pages in repo settings (one-time)
- [ ] Confirm the `Docs` workflow runs green on main
- [ ] Confirm https://tredmann.github.io/repahead/ resolves

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Merge the PR after review.

- [ ] **Step 2: Enable GitHub Pages with the Actions source**

In the repository: **Settings → Pages → Build and deployment → Source: GitHub Actions.**

This is required exactly once. Without it, `actions/deploy-pages@v4` fails with a configuration error on the first run.

- [ ] **Step 3: Trigger the workflow**

The merge commit already triggered it via the `push to main` rule. If you want to re-run manually:

```bash
gh workflow run docs.yml
gh run watch
```

Expected: both `build` and `deploy` jobs succeed.

- [ ] **Step 4: Verify the site is live**

```bash
curl -sSfo /dev/null -w "%{http_code}\n" https://tredmann.github.io/repahead/
```

Expected: `200`.

Spot-check a few internal links by opening the site in a browser:

- Home loads with the navigation tabs visible.
- Each tab (Installation, Configuration, Publishing, Consuming, Endpoints, Troubleshooting) opens its page.
- Search returns results when querying for `AUTH_PASS`.
- Switching between light and dark mode works via the palette toggle.

If any of these fail, re-open Task 1 and check that the `mkdocs.yml` values for `nav`, `theme.features`, and `theme.palette` match the spec exactly.
