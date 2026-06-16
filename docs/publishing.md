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
- If `composer.json`'s `name` is present and disagrees with the folder path, **the Release is Rejected** and a warning is logged. Either omit the `name` field or make it match the folder path.

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
