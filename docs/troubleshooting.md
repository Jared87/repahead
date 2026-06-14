# Troubleshooting

## 401 Unauthorized

A request to `/packages.json`, `/dist/...`, or `/rebuild` returns 401.

- **Wrong credentials.** Confirm the username matches `AUTH_USER` (defaults to `ci`) and the password matches `AUTH_PASS`. Both are set as env vars on the server.
- **No credentials sent.** Composer reads basic-auth from `auth.json` or `COMPOSER_AUTH`. See [Consuming → Authentication in CI](consuming.md#authentication-in-ci).
- **Hitting `/health` by mistake.** Only `/health` is unauthenticated; everything else requires basic auth.

## Index seems stale

`/packages.json` is showing an old set of Releases.

- The Index is cached. Wait `LISTING_TTL_SECONDS` for the TTL to expire, **or** explicitly force a rebuild with `POST /rebuild`.
- If you have already called `POST /rebuild` and the response shows the right counts but `/packages.json` still returns the old set, you are hitting a downstream cache (CDN, reverse proxy, browser). Check that `APP_BASE_URL` points at the right host and bust the upstream cache.

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
