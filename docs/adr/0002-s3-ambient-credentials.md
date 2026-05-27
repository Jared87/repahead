# ADR-0002: S3 Ambient Credentials via SDK Provider Chain

**Date:** 2026-05-27
**Status:** Accepted

## Context

The original `Storage::makeS3()` required `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, and `AWS_REGION` at boot and passed them explicitly to `S3Client`. This works for deployments that supply static credentials, but breaks when the server runs on AWS infrastructure (EC2 instance profile, ECS task role, Lambda execution role) where credentials are injected by the runtime and no static key/secret is available.

The AWS SDK for PHP resolves credentials through a provider chain (env vars → `~/.aws/credentials` → ECS container credentials → EC2 instance metadata) automatically when no explicit `credentials` key is passed to `S3Client`. Region is resolved similarly.

## Decision

Make `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, and `AWS_REGION` all optional for S3 storage:

- If both key and secret are present, pass them explicitly as before.
- If neither is present, omit the `credentials` key entirely and let the SDK provider chain resolve them.
- If exactly one is present, throw an `InvalidArgumentException` naming both vars — this catches operator typos before the first request fails.
- If `AWS_REGION` is present, pass it. If absent, omit it and let the SDK resolve it from instance metadata.

Explicit credentials continue to work unchanged; the change only lifts the boot-time requirement for deployments that rely on ambient AWS authentication.

## Considered Options

Dropping explicit credential support entirely was considered and rejected. It would silently break deployments running outside AWS (local dev, non-AWS hosting) that currently pass static credentials via env.
