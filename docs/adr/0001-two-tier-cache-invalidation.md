# ADR-0001: Two-Tier Cache Invalidation (TTL + Listing Fingerprint)

**Date:** 2026-05-21
**Status:** Accepted

## Context

Serving the Index requires knowing what Releases are in Storage. For S3 storage, listing a large prefix on every request is expensive (API cost + latency). A plain TTL avoids that cost but means a freshly Published Release is invisible until the TTL window expires — even if the Publisher calls `POST /rebuild` to force immediate availability.

A content-only check (always list, rebuild only when the Listing Fingerprint changes) removes the latency protection: every request pays the S3 listing cost.

## Decision

Use two independent checks in sequence:

1. **TTL check** — if `cache/manifest.hash` is younger than `LISTING_TTL_SECONDS`, serve the cached Index without listing Storage at all.
2. **Listing Fingerprint check** — once the TTL expires, list Storage and compare the resulting SHA-256 hash against the stored Fingerprint. Only rebuild the Index if the Fingerprint changed; otherwise reset the TTL and serve the existing cached Index.

`POST /rebuild` invalidates the Cache by deleting `manifest.hash`, which forces the Fingerprint check on the next request regardless of TTL. This gives Publishers a way to surface a new Release immediately without waiting for the TTL window.

## Consequences

- S3 listing calls are bounded by `LISTING_TTL_SECONDS`, not by request rate.
- A freshly Published Release is invisible to Consumers for up to `LISTING_TTL_SECONDS` unless the Publisher calls `POST /rebuild`.
- Setting `LISTING_TTL_SECONDS=0` disables the TTL tier entirely — every request lists Storage, and `POST /rebuild` has no additional effect.
- The Fingerprint tier means a TTL expiry that finds no Storage changes is cheap: one list + one hash comparison, no ZIP reads.
