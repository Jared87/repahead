# GitHub Actions Release Pipeline — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create a GitHub Actions workflow that runs quality checks and publishes a multi-platform Docker image to Docker Hub whenever a `v*.*.*` tag is pushed.

**Architecture:** Single workflow file with two sequential jobs: `quality` (rector dry-run, pint check, phpunit, phpstan) gates `docker` (QEMU + Buildx multi-platform build, pushes `1.0.0` + `latest` to `tredmann/repahead`). Quality runs in CI check mode — dry/test flags — so it fails fast if committed code wasn't already passing the local pipeline.

**Tech Stack:** GitHub Actions, `shivammathur/setup-php@v2`, `actions/cache@v4`, `docker/setup-qemu-action@v3`, `docker/setup-buildx-action@v3`, `docker/login-action@v3`, `docker/build-push-action@v5`

---

### Task 1: Create the workflow file

**Files:**
- Create: `.github/workflows/release.yml`

- [ ] **Step 1: Create the directory**

```bash
mkdir -p .github/workflows
```

- [ ] **Step 2: Write `.github/workflows/release.yml`**

```yaml
name: Release

on:
  push:
    tags:
      - 'v[0-9]+.[0-9]+.[0-9]+'

jobs:
  quality:
    name: Quality
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Set up PHP 8.4
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: fileinfo, json, zip
          coverage: none

      - name: Cache Composer packages
        uses: actions/cache@v4
        with:
          path: vendor
          key: ${{ runner.os }}-composer-${{ hashFiles('**/composer.lock') }}
          restore-keys: |
            ${{ runner.os }}-composer-

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Rector (dry run)
        run: composer rector:dry

      - name: Pint (check)
        run: composer pint:test

      - name: PHPUnit
        run: composer test

      - name: PHPStan
        run: composer stan

  docker:
    name: Docker
    runs-on: ubuntu-latest
    needs: quality
    steps:
      - uses: actions/checkout@v4

      - name: Derive Docker tag
        id: tag
        run: echo "version=${GITHUB_REF_NAME#v}" >> "$GITHUB_OUTPUT"

      - name: Set up QEMU
        uses: docker/setup-qemu-action@v3

      - name: Set up Buildx
        uses: docker/setup-buildx-action@v3

      - name: Log in to Docker Hub
        uses: docker/login-action@v3
        with:
          username: ${{ secrets.DOCKERHUB_USERNAME }}
          password: ${{ secrets.DOCKERHUB_TOKEN }}

      - name: Build and push
        uses: docker/build-push-action@v5
        with:
          context: .
          platforms: linux/amd64,linux/arm64
          push: true
          tags: |
            tredmann/repahead:${{ steps.tag.outputs.version }}
            tredmann/repahead:latest
```

- [ ] **Step 3: Validate YAML syntax**

```bash
python3 -c "import yaml; yaml.safe_load(open('.github/workflows/release.yml')); print('YAML OK')"
```

Expected output: `YAML OK`

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/release.yml
git commit -m "ci: add release workflow (quality + Docker push)"
```

---

### Task 2: Add secrets to GitHub

Manual steps in the GitHub web UI — no code changes.

- [ ] **Step 1: Create a Docker Hub access token**

1. Go to hub.docker.com → click avatar → Account Settings → Security → New Access Token
2. Name it `repahead-github-actions`, permission: **Read, Write, Delete**
3. Copy the token immediately — shown only once

- [ ] **Step 2: Add secrets to the GitHub repository**

1. GitHub repo → Settings → Secrets and variables → Actions → New repository secret
2. Add `DOCKERHUB_USERNAME` = `tredmann`
3. Add `DOCKERHUB_TOKEN` = token from step 1

---

### Task 3: Verify end-to-end

- [ ] **Step 1: Push branch and tag**

```bash
git push origin main
git tag v0.1.0
git push origin v0.1.0
```

- [ ] **Step 2: Watch the workflow**

GitHub repo → Actions tab → `Release` workflow should appear. Both `Quality` and `Docker` jobs must go green.

- [ ] **Step 3: Verify the image on Docker Hub**

```bash
docker pull tredmann/repahead:0.1.0
docker pull tredmann/repahead:latest
```

Both pulls must succeed. Confirm tag `0.1.0` (no `v`) and `latest` both exist on hub.docker.com/r/tredmann/repahead.
