# Private Composer Server

A small PHP service that exposes a private Composer (Packagist-compatible) repository. Publishers drop Release ZIPs into Storage; the service builds and serves the Index. Storage is pluggable via Flysystem (local disk or S3).

See [docs/superpowers/specs/2026-05-06-composer-server-design.md](docs/superpowers/specs/2026-05-06-composer-server-design.md) for the full design.

## Quick start (Docker)

```bash
cp .env.example .env
# edit AUTH_PASS at minimum
AUTH_PASS=$(grep AUTH_PASS .env | cut -d= -f2) docker compose up -d --build
```

The service listens on `http://localhost:8080`. Publish a Release to the `composer-zips` volume:

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

The folder path (`vendor/package`) determines the Package identity; the filename determines the Release version. The `composer.json` inside the ZIP is the source for `require`, `autoload`, etc. — but the path wins over `composer.json`'s `name` field if they disagree.

## Endpoints

| Method | Route | Auth | Purpose |
|--------|-------|------|---------|
| GET | `/health` | none | Storage liveness probe — `{"status":"ok"}` or 503 |
| GET | `/packages.json` | basic | The Index — Composer repository index (cached) |
| GET | `/dist/{vendor}/{package}/{version}.zip` | basic | Stream a Release ZIP |
| POST | `/rebuild` | basic | Force an Index rebuild; returns `{packages, versions, skipped, duration_ms}` |

`/health` is intentionally unauthenticated so Docker and load-balancer health checks work without credentials. All other endpoints require HTTP basic auth (`AUTH_USER` / `AUTH_PASS`).

The Dockerfile includes a baked-in `HEALTHCHECK` that polls `GET /health` every 30 seconds (`curl -sf http://localhost:8080/health`).

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
- `LISTING_TTL_SECONDS=30` — TTL tier: how long before Storage is re-listed (`0` = list on every request)
- `AUTH_USER`, `AUTH_PASS` — single shared HTTP basic credential

## S3 storage

Set `STORAGE_DSN` to `s3:bucket-name` or `s3:bucket-name/optional/prefix`.

**With explicit credentials** (local dev, non-AWS hosting):

```
STORAGE_DSN=s3:my-bucket/composer/zips
AWS_ACCESS_KEY_ID=AKIA...
AWS_SECRET_ACCESS_KEY=...
AWS_REGION=eu-central-1
```

**With ambient credentials** (EC2 instance profile, ECS task role, Lambda execution role):

```
STORAGE_DSN=s3:my-bucket/composer/zips
AWS_REGION=eu-central-1
```

Omit `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` entirely — the AWS SDK resolves credentials automatically from the runtime environment. `AWS_REGION` must still be set explicitly on EC2; Lambda sets it automatically as `AWS_REGION`, and ECS sets it as `AWS_DEFAULT_REGION`.

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

Publish Releases to S3 using the same `vendor/package/version.zip` layout as local Storage, then `POST /rebuild` to refresh the Index immediately.

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
| `LISTING_TTL_SECONDS` | `30` | TTL tier: seconds before Storage is re-listed; `0` = list on every request |
| `AWS_ACCESS_KEY_ID` | — | S3 only — explicit AWS access key ID; omit to use ambient credentials (instance profile, task role, Lambda role) |
| `AWS_SECRET_ACCESS_KEY` | — | S3 only — explicit AWS secret access key; must be set together with `AWS_ACCESS_KEY_ID` or not at all |
| `AWS_REGION` | — | S3 only — AWS region (e.g. `eu-central-1`); Lambda sets this automatically, ECS sets `AWS_DEFAULT_REGION` |
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

Releases that are corrupt, missing `composer.json`, or whose `composer.json` `name` field doesn't match the folder path are **Rejected** — excluded from the Index and logged to stderr. The Rejected count is included in `POST /rebuild` responses.
