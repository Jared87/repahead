# Private Composer Server

A small PHP service that exposes a private Composer (Packagist-compatible) repository whose content is driven by **dropping ZIP files into a folder**. Storage is pluggable via Flysystem (local disk or S3).

See [docs/superpowers/specs/2026-05-06-composer-server-design.md](docs/superpowers/specs/2026-05-06-composer-server-design.md) for the full design.

## Quick start (Docker)

```bash
cp .env.example .env
# edit AUTH_PASS at minimum
AUTH_PASS=$(grep AUTH_PASS .env | cut -d= -f2) docker compose up -d --build
```

The service listens on `http://localhost:8080`. Drop ZIPs into the `composer-zips` volume:

```bash
docker compose cp ./acme-billing-1.2.0.zip composer:/var/www/html/zips/acme/billing/1.2.0.zip
curl -u ci:secret -X POST http://localhost:8080/rebuild
```

## Folder layout for ZIPs

```
zips/
  vendor/
    package/
      1.0.0.zip
      1.1.0.zip
```

The `composer.json` inside each ZIP is the source of truth for `require`, `autoload`, etc. The folder path determines `vendor/package`; the filename determines the version.

## Endpoints

| Method | Route | Purpose |
|--------|-------|---------|
| GET | `/packages.json` | Composer repository index (cached) |
| GET | `/dist/{vendor}/{package}/{version}.zip` | Streams the ZIP |
| POST | `/rebuild` | Force cache rebuild; returns `{packages, versions, skipped, duration_ms}` |

All endpoints use HTTP basic auth (`AUTH_USER` / `AUTH_PASS`).

## Consumer setup

In the consuming project:

```bash
composer config repositories.private composer https://composer.your-domain.com
composer config http-basic.composer.your-domain.com ci <password>
composer require acme/billing:^1.0
```

## Configuration

See `.env.example`. Key vars:

- `STORAGE_DSN=local:./zips` or `s3:my-bucket/composer/zips`
- `LISTING_TTL_SECONDS=30` — how long the cache lives between storage listings (`0` = list every request)
- `AUTH_USER`, `AUTH_PASS` — single shared HTTP basic credential

## S3 storage

Set `STORAGE_DSN` to `s3:bucket-name` or `s3:bucket-name/optional/prefix`, then supply the three AWS credentials:

```
STORAGE_DSN=s3:my-bucket/composer/zips
AWS_ACCESS_KEY_ID=AKIA...
AWS_SECRET_ACCESS_KEY=...
AWS_REGION=eu-central-1
```

The service only reads from S3 (list + download). The minimum IAM policy for the bucket is:

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

Upload ZIPs to S3 in the same `vendor/package/version.zip` layout used for local storage, then `POST /rebuild` to refresh the index.

## Docker Hub

Image: [`tredmann/repahead`](https://hub.docker.com/r/tredmann/repahead)

```bash
# minimal — only AUTH_PASS is required
docker run -d -p 8080:8080 -e AUTH_PASS=secret tredmann/repahead

# production — set the public URL so dist links resolve correctly
docker run -d \
  -p 8080:8080 \
  -e AUTH_PASS=secret \
  -e APP_BASE_URL=https://composer.your-domain.com \
  -v /path/to/zips:/var/www/html/zips \
  tredmann/repahead
```

### Environment variables

| Variable | Default | Description |
|----------|---------|-------------|
| `AUTH_PASS` | — | **Required.** HTTP basic auth password |
| `AUTH_USER` | `ci` | HTTP basic auth username |
| `APP_BASE_URL` | `http://localhost:8080` | Public base URL; used in `packages.json` dist download links |
| `STORAGE_DSN` | `local:/var/www/html/zips` | Storage backend — `local:<path>` or `s3:<bucket>/<prefix>` |
| `CACHE_DIR` | `/var/www/html/cache` | Directory for the `packages.json` cache and hash files |
| `LISTING_TTL_SECONDS` | `30` | Seconds before the storage listing cache expires; `0` = list on every request |
| `AWS_ACCESS_KEY_ID` | — | S3 only — AWS access key ID |
| `AWS_SECRET_ACCESS_KEY` | — | S3 only — AWS secret access key |
| `AWS_REGION` | — | S3 only — AWS region, e.g. `eu-central-1` |
| `SERVER_NAME` | `:8080` | Listen address and port (base image) |
| `AUTOMATIC_HTTPS` | `off` | Auto-HTTPS via FrankenPHP/Caddy; keep `off` behind a reverse proxy (base image) |
| `PHP_OPCACHE_ENABLE` | `1` | Enable PHP OPcache (base image) |

## Local development

```bash
composer install
cp .env.example .env
php -S 127.0.0.1:8080 -t public
composer test     # full suite (also: composer stan, composer pint, composer rector)
```

## Failure modes

ZIPs that are corrupt, missing `composer.json`, or whose `composer.json` `name` field doesn't match the folder path are **skipped** (logged to stderr). The skipped count is included in `POST /rebuild` responses.
