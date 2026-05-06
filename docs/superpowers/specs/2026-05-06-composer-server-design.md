---
title: Static-feeling Private Composer Repository Server
date: 2026-05-06
status: design
---

# Private Composer Repository Server — Design

## Goal

Provide a private PHP Composer (Packagist-compatible) repository whose content is driven entirely by **dropping ZIP files into a folder**. No build step, no admin UI, no database. The server figures out the package catalog from the storage layout and the `composer.json` files embedded in each ZIP.

The folder may live on local disk **or** on any object store supported by Flysystem (S3, etc.).

## Non-goals (v1)

- No upload/admin UI. Files arrive out of band (`scp`, `aws s3 cp`, CI artifact step, …).
- No multi-tenant access control. Single shared HTTP basic-auth credential.
- No dev branches, only versioned releases (`1.2.0.zip`).
- No partial cache invalidation. Catalog is rebuilt as a unit.
- No background workers or queues.
- No `metadata-url` v2 protocol — the inline format is enough at this scale.

## High-level architecture

A small PHP application doing three things:

1. **Scan** the configured storage prefix for `*.zip` files, listing them as a flat catalog of `(vendor, package, version, path, size, lastModified)` tuples.
2. **Read** the embedded `composer.json` from each ZIP using `ZipArchive`. For non-local storage, the ZIP is streamed to a temporary file first (`ZipArchive` cannot read from a stream).
3. **Serve** a Composer-compatible `packages.json` and the ZIP downloads, both behind HTTP basic auth.

The folder is the source of truth. Everything else is derived from it and cached.

## Folder layout (storage)

Two-level folder per package, mirroring Composer's `vendor/name` convention. Filename = version, suffix `.zip`.

```
zips/
  acme/
    billing/
      1.2.0.zip
      1.3.0.zip
    utils/
      0.5.1.zip
  beta/
    sdk/
      2.0.0.zip
```

Rules:

- `vendor` and `package` are read from the directory path, not from `composer.json`. A typo in `composer.json`'s `name` field is logged as a mismatch and the file is skipped (see "Validation" below).
- `version` is read from the filename (everything before `.zip`).
- The `composer.json` inside the ZIP is the source for `require`, `autoload`, `type`, etc.

## URL routes

All routes are behind HTTP basic auth.

| Method | Route | Purpose |
|--------|-------|---------|
| GET | `/packages.json` | Composer repository index (cached) |
| GET | `/dist/{vendor}/{package}/{version}.zip` | Streams the ZIP for download |
| POST | `/rebuild` | Forces a cache rebuild; returns a JSON summary |

Auth uses HTTP Basic; consumers configure it on their side via `composer config http-basic.<host> <user> <pass>`.

## `packages.json` format

Inline v1 format (Composer 2 still reads it). One file lists all packages and versions.

Example shape (one entry):

```json
{
  "packages": {
    "acme/billing": {
      "1.2.0": {
        "name": "acme/billing",
        "version": "1.2.0",
        "type": "library",
        "require": { "php": "^8.2" },
        "autoload": { "psr-4": { "Acme\\Billing\\": "src/" } },
        "dist": {
          "type": "zip",
          "url": "https://composer.your-domain.com/dist/acme/billing/1.2.0.zip",
          "shasum": "<sha1 of the zip>"
        }
      }
    }
  }
}
```

All fields except the `dist` block are copied through from the ZIP's embedded `composer.json`. The `dist` block is synthesized: URL is the `/dist/...` route on this server, `shasum` is computed during cache rebuild.

## Caching

Cache files live in a local directory (small JSON, always on local disk regardless of storage backend):

```
cache/
  packages.json     # the response we serve, byte-for-byte
  manifest.hash     # one line: sha256 of the storage listing snapshot
  .rebuild.lock     # flock sentinel to prevent concurrent rebuilds
```

### Request flow for `GET /packages.json`

```
0. If cache/packages.json AND cache/manifest.hash both exist
   AND LISTING_TTL_SECONDS > 0
   AND time() - mtime(cache/manifest.hash) < TTL
     -> serve cached packages.json, skip listing entirely.

1. Otherwise list the storage prefix recursively.
   For each file: capture (path, size, lastModified).
   Sort, serialize, sha256 -> currentHash.

2. If currentHash == content of cache/manifest.hash
     -> touch cache/manifest.hash (so the TTL window restarts)
     -> serve cached packages.json.

3. Otherwise (mismatch or no cache yet) -> rebuild:
     a. flock(cache/.rebuild.lock, LOCK_EX). Block on contention.
     b. Re-check the hash inside the lock (another worker may have rebuilt).
        If now equal -> drop lock, serve cached.
     c. For each catalog entry:
          - Stream ZIP to temp file (or use directly if local).
          - Open with ZipArchive, read 'composer.json' entry.
          - Compute sha1 of the ZIP for the dist block.
          - Skip with a logged warning on any failure (see Validation).
     d. Build full packages.json string.
     e. Write packages.json.tmp + manifest.hash.tmp, then atomic rename().
     f. Release lock; serve the new packages.json.
```

### `POST /rebuild`

Synchronously deletes `cache/manifest.hash`, runs the rebuild path above, and returns:

```json
{ "packages": 12, "versions": 47, "skipped": 1, "duration_ms": 420 }
```

Idempotent.

### `GET /dist/{vendor}/{package}/{version}.zip`

No caching; streams bytes from Flysystem (`readStream` → `fpassthru`) with `Content-Type: application/zip` and a `Content-Disposition: attachment` header. Composer verifies integrity itself using the `shasum` from `packages.json`.

## Configuration

Single `.env` (loaded with `vlucas/phpdotenv`):

```
APP_BASE_URL=https://composer.your-domain.com

# Storage
STORAGE_DSN=local:./zips
# or: STORAGE_DSN=s3:my-bucket/composer/zips
# AWS_ACCESS_KEY_ID=...
# AWS_SECRET_ACCESS_KEY=...
# AWS_REGION=eu-central-1

# Cache (always local disk)
CACHE_DIR=./cache
LISTING_TTL_SECONDS=30

# Auth
AUTH_USER=ci
AUTH_PASS=replace-me
```

`STORAGE_DSN` is parsed as `<scheme>:<rest>`:
- `local:<path>` → Flysystem local adapter rooted at `<path>`
- `s3:<bucket>/<prefix>` → Flysystem S3 v3 adapter with `<prefix>` as path prefix

`LISTING_TTL_SECONDS=0` disables the TTL — every request will list storage. Higher values reduce listing calls (relevant on S3 if the prefix grows large) at the cost of up-to-30s lag before a freshly dropped ZIP is visible. Combine with `POST /rebuild` to force-refresh on demand.

## Application structure

```
composer-server/
├── public/
│   └── index.php              # entry point: bootstrap + dispatch
├── src/
│   ├── Config.php             # loads .env, exposes typed getters
│   ├── Storage.php            # Flysystem factory from STORAGE_DSN
│   ├── Catalog.php            # walks storage, returns catalog entries
│   ├── ZipMetadata.php        # extracts composer.json + sha1 from a ZIP
│   ├── Cache.php              # read/write packages.json + manifest.hash + locking
│   ├── PackagesJson.php       # builds the response JSON from catalog + metadata
│   ├── Auth.php               # PSR-15 basic-auth middleware
│   └── Controller.php         # the three route handlers
├── cache/                     # auto-generated, .gitignore'd
├── zips/                      # local-storage default; .gitignore'd
├── .env.example
├── compose.yml
├── Dockerfile                 # only if customising the base image
├── composer.json
└── README.md
```

### Component responsibilities

- **`Config`** — single source of truth for env settings. Typed getters (`storageDsn(): string`, `listingTtl(): int`, …).
- **`Storage`** — `make(string $dsn): Filesystem`. Switches on the DSN scheme, configures the appropriate Flysystem adapter. Nothing else in the app talks to disk directly.
- **`Catalog`** — `list(Filesystem $fs): iterable<CatalogEntry>`. Returns lightweight DTOs `(vendor, package, version, path, size, lastModified)`. Does **not** open any ZIPs.
- **`ZipMetadata`** — `read(Filesystem $fs, string $path): ZipMeta`. Streams to `tempnam()` if necessary, opens with `ZipArchive`, reads the embedded `composer.json`, computes sha1 of the ZIP. Cleans up the temp file in `finally`.
- **`Cache`** — owns `cache/packages.json` and `cache/manifest.hash`. Public API: `getOrRebuild(string $currentHash, callable $rebuild): string` and `forceRebuild(callable $rebuild): RebuildSummary`. Internally handles flock, atomic write, TTL touch.
- **`PackagesJson`** — `build(iterable<CatalogEntry> $entries, callable $metadataReader, string $baseUrl): string`. Pure function-style; the metadata reader is injected so tests can stub `ZipMetadata`.
- **`Auth`** — PSR-15 middleware. Compares credentials with `hash_equals()`; emits `401 WWW-Authenticate: Basic realm="composer"` on failure.
- **`Controller`** — three small methods (`packages`, `dist`, `rebuild`), each delegating to the above components.

### Dependencies

- `league/flysystem`
- `league/flysystem-aws-s3-v3` (optional, only loaded when DSN scheme is `s3`)
- `league/route`
- `laminas/laminas-diactoros` (PSR-7 implementation)
- `laminas/laminas-httphandlerrunner` (PSR-7 emitter)
- `vlucas/phpdotenv`

PHP requirements: `>=8.2`, `ext-zip`, `ext-json`, `ext-fileinfo`.

## Validation & failure modes

All of these are non-fatal — the offending ZIP is skipped, a warning is logged, the rebuild succeeds with the remaining catalog. The `POST /rebuild` summary's `skipped` count surfaces this; the log is the primary feedback channel for v1.

- ZIP missing `composer.json` → skip, log path.
- `ZipArchive::open()` fails (corrupt ZIP) → skip, log path.
- `composer.json` missing required fields (`name`, `version`, …) → skip, log.
- `composer.json` `name` mismatches `vendor/package` from the folder path → skip, log both.
- Filename version mismatches `composer.json` `version` → filename wins, log a warning (rare in practice; informational).
- Storage listing fails entirely → 503, leave existing cache untouched.

## Auth & deployment

- **TLS:** terminated by FrankenPHP/Caddy automatically (`AUTOMATIC_HTTPS=on`), or by a fronting load balancer. Basic auth over plaintext is unacceptable.
- **Process model:** single FrankenPHP container, request-mode (no worker mode needed for v1). PHP-FPM + nginx is an acceptable alternative if your ops team prefers it.
- **Filesystem permissions (local-storage mode):** web user needs read on `zips/` and read+write on `cache/`. Nothing else.
- **S3 permissions:** IAM policy allows `s3:ListBucket` (with prefix condition) and `s3:GetObject`. The server **never** writes to storage.

## Container

Base image: **`serversideup/php:8.4-frankenphp`**. Single-process container, automatic HTTPS, sensible PHP/OPcache defaults, non-root user, env-driven config.

```yaml
# compose.yml (sketch)
services:
  composer:
    image: serversideup/php:8.4-frankenphp
    environment:
      SERVER_NAME: composer.your-domain.com
      AUTOMATIC_HTTPS: "on"
      PHP_OPCACHE_ENABLE: "1"
      AUTH_USER: ci
      AUTH_PASS: ${AUTH_PASS}
      STORAGE_DSN: local:/var/www/html/zips
      LISTING_TTL_SECONDS: "30"
    volumes:
      - ./app:/var/www/html
      - composer-zips:/var/www/html/zips
      - composer-cache:/var/www/html/cache
    ports:
      - "80:80"
      - "443:443"
    restart: unless-stopped

volumes:
  composer-zips:
  composer-cache:
```

For S3 storage, drop the `composer-zips` volume and pass AWS credentials as env vars instead. The cache volume stays — it's a small local JSON file kept beside the app.

## Operational story (drop-a-ZIP UX, end to end)

1. Local mode: `scp acme/billing/1.3.0.zip server:/var/lib/composer-server/zips/acme/billing/1.3.0.zip`
2. S3 mode: `aws s3 cp 1.3.0.zip s3://my-bucket/composer/zips/acme/billing/1.3.0.zip`
3. Optional (when `LISTING_TTL_SECONDS > 0`): `curl -u ci:pass -X POST https://composer.your-domain.com/rebuild`
4. Consumer side: `composer require acme/billing:^1.3` — pulls the new version.

Consumer one-time setup:

```bash
composer config repositories.private composer https://composer.your-domain.com
composer config http-basic.composer.your-domain.com ci <password>
```

## Future considerations (not in scope for v1)

- 302 redirect to S3 presigned URLs in `/dist/...` to offload bandwidth from the PHP host.
- Composer v2 metadata-url protocol for finer-grained client-side caching.
- A small admin endpoint listing catalog contents and skipped files for human inspection.
- Per-user credentials / token revocation.
- Dev branch support (`dev-main.zip`, `dev-feature-foo.zip`) with branch-alias handling.
