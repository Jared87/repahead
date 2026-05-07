# GitHub Actions Release Pipeline — Design

## Goal

Automate quality checks and Docker image publication whenever a semver release tag is pushed to the repository.

## Trigger

The workflow fires on `push` events matching tags of the form `v[0-9]+.[0-9]+.[0-9]+` (strict semver, no pre-release suffixes). Tagging convention for the developer: `git tag v1.0.0 && git push origin v1.0.0`.

## Jobs

### Job 1 — `quality`

Runs on `ubuntu-latest`.

Steps:
1. Checkout the repository.
2. Set up PHP 8.4 via `shivammathur/setup-php` with extensions `fileinfo`, `json`, `zip`.
3. `composer install --no-interaction --prefer-dist`.
4. Run quality tools in order, stopping on first failure:
   - `composer rector`
   - `composer pint`
   - `composer test`
   - `composer stan`

### Job 2 — `docker`

Runs on `ubuntu-latest`. Depends on `quality` (skipped if quality fails).

Steps:
1. Checkout the repository.
2. Derive the Docker tag by stripping the leading `v` from the git ref (e.g. `refs/tags/v1.0.0` → `1.0.0`).
3. Set up QEMU via `docker/setup-qemu-action` (required for cross-compilation).
4. Set up Buildx via `docker/setup-buildx-action`.
5. Log in to Docker Hub via `docker/login-action` using repository secrets.
6. Build and push via `docker/build-push-action`:
   - Platforms: `linux/amd64,linux/arm64`
   - Tags: `tredmann/repahead:<version>` and `tredmann/repahead:latest`

## Tagging Convention

| Context | Format | Example |
|---------|--------|---------|
| Git tag | `v<semver>` | `v1.0.0` |
| Docker Hub | `<semver>` | `1.0.0` |
| Docker Hub alias | `latest` | `latest` |

`v` prefix on git tags is the GitHub/git-ecosystem standard. Docker Hub images conventionally omit it.

## Secrets Required

Two secrets must be added to the GitHub repository under Settings → Secrets and variables → Actions:

| Secret name | Value |
|-------------|-------|
| `DOCKERHUB_USERNAME` | `tredmann` |
| `DOCKERHUB_TOKEN` | Docker Hub access token (hub.docker.com → Account Settings → Security → New Access Token) |

Using an access token (not the account password) is required — Docker Hub deprecated password-based auth for API access.

## File Location

`.github/workflows/release.yml`
