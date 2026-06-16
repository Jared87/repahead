# Configuration

repahead is configured entirely through environment variables. In Docker, pass them via `docker run -e` or `compose.yml`. In a local install, populate `.env` (loaded automatically at boot).

## Environment variable reference

| Variable | Default | Description |
|----------|---------|-------------|
| `AUTH_PASS` | — | **Required.** HTTP basic auth password. |
| `AUTH_USER` | `ci` | HTTP basic auth username. |
| `APP_BASE_URL` | `http://localhost:8080` | Public base URL; used in `packages.json` Dist URLs. Set this in production so Composer clients fetch from the right host. |
| `STORAGE_DSN` | `local:/var/www/html/zips` | Storage backend. See [Storage backends](#storage-backends). |
| `CACHE_DIR` | `/var/www/html/cache` | Directory for the Cache — the Index, the Listing Fingerprint, and the rebuild lock. Always local, regardless of the Storage backend. |
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

repahead decides whether to serve the Cached Index through two independent checks:

1. **Listing TTL** (`LISTING_TTL_SECONDS`) — how often Storage is re-listed. Defaults to 30 seconds.
2. **Listing Fingerprint** — even after a fresh listing, the cached Index is reused unchanged if the Release set has not changed (SHA-256 match).

Set `LISTING_TTL_SECONDS=0` to disable the TTL and always re-list. Use `POST /rebuild` to force an immediate rebuild without waiting for the TTL.

See [ADR-0001 — Two-tier cache invalidation](https://github.com/tredmann/repahead/blob/main/docs/adr/0001-two-tier-cache-invalidation.md) for the rationale.
