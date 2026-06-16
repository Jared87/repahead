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

The URL appears in each Release's Dist block (the `dist.url` field) in the Index — Composer follows it during `composer install`. Do not construct these URLs by hand.

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
