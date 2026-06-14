# GitHub Pages Documentation Site — Design

## Goal

Publish a polished, searchable documentation site for repahead at `https://tredmann.github.io/repahead/`, aimed at operators (deploying the service) and publishers/consumers (pushing ZIPs and consuming the repository). The README continues to serve a fast quick-start on GitHub; the site holds full reference material.

## Generator

MkDocs Material. Single `mkdocs.yml` at repo root, source in the existing `docs/` folder, build via GitHub Actions, deploy via the official Pages artifact flow.

## Content Split

| Surface | Purpose | Scope |
|---------|---------|-------|
| README | Pitch + fast on-ramp | Title, one-paragraph description, Docker quick-start, link to site, link to design specs (contributor) |
| Site | Full reference | Install, configure, publish, consume, endpoints, troubleshooting |

No content duplication. Each surface is the source of truth for its scope.

## Site Pages

All under `docs/`:

| File | Replaces / sources from | Content |
|------|--------------------------|---------|
| `index.md` | new | Landing: what repahead is, one-line example, CTA to install |
| `installation.md` | README §Quick start | Docker compose flow and local PHP 8.2+ flow, side by side |
| `configuration.md` | README + `.env.example` | Env-var reference table; `STORAGE_DSN` (`local:` and `s3:`, both ambient and explicit S3 credentials); `AUTH_USER` / `AUTH_PASS`; `LISTING_TTL_SECONDS` and the two-tier cache model (cross-link to ADR-0001 on GitHub) |
| `publishing.md` | README §Folder layout for ZIPs | Folder-path-as-identity rule, filename-as-version rule, `composer.json`-inside-ZIP semantics, `POST /rebuild` |
| `consuming.md` | README §Consumer setup | `composer.json` `repositories` block, auth via `auth.json` / env, troubleshooting first install |
| `endpoints.md` | README §Endpoints | The 4-route table with response shape, auth requirement, and status codes per route |
| `troubleshooting.md` | new | 401s, stale cache, `/health` failing, S3 permissions errors |

## Repository Layout

```
mkdocs.yml                  # NEW: MkDocs config
docs/                       # existing folder, also MkDocs source
  requirements.txt          # NEW: pinned mkdocs-material
  index.md                  # NEW
  installation.md           # NEW
  configuration.md          # NEW
  publishing.md             # NEW
  consuming.md              # NEW
  endpoints.md              # NEW
  troubleshooting.md        # NEW
  adr/                      # existing — excluded from site
  superpowers/              # existing — excluded from site
.github/workflows/docs.yml  # NEW
README.md                   # trimmed
```

Existing `docs/adr/` and `docs/superpowers/` stay in place (they are referenced by `CLAUDE.md` and are project history). They are excluded from the published site via MkDocs Material's built-in `exclude_docs` config — no plugin and no folder restructuring.

## MkDocs Config (`mkdocs.yml`)

Required keys:

- `site_name: repahead`
- `site_url: https://tredmann.github.io/repahead/`
- `repo_url: https://github.com/tredmann/repahead`
- `repo_name: tredmann/repahead`
- `edit_uri: edit/main/docs/`
- `theme:` — `name: material`, palette with light/dark toggle, features: `navigation.tabs`, `navigation.sections`, `content.code.copy`, `content.action.edit`, `search.suggest`, `search.highlight`
- `exclude_docs:` — `adr/`, `superpowers/`
- `nav:` — explicit list ordering the seven pages above
- `markdown_extensions:` — `admonition`, `pymdownx.superfences`, `pymdownx.highlight`, `pymdownx.tabbed` (for the Docker / local install tabs), `attr_list`, `tables`

## Dependency Pinning

`docs/requirements.txt`:

```
mkdocs-material==<latest-stable-at-implementation>
```

A specific version is pinned so a future Material release cannot silently break the build. Renovate / Dependabot may bump it later.

## Build & Deploy Workflow (`.github/workflows/docs.yml`)

Triggers:

- `push` to `main` with `paths: ['docs/**', 'mkdocs.yml', '.github/workflows/docs.yml']`
- `workflow_dispatch` for manual rebuilds

Permissions:

- `contents: read`
- `pages: write`
- `id-token: write`

Concurrency group `pages` with `cancel-in-progress: false` so concurrent main pushes serialize cleanly.

Jobs:

### Job 1 — `build`

Runs on `ubuntu-latest`.

Steps:
1. `actions/checkout@v4`
2. `actions/setup-python@v5` with Python 3.12
3. `pip install -r docs/requirements.txt`
4. `mkdocs build --strict` (fail the build on broken internal links or warnings)
5. `actions/upload-pages-artifact@v3` with `path: site/`

### Job 2 — `deploy`

Runs on `ubuntu-latest`. Depends on `build`. Uses the `github-pages` environment.

Steps:
1. `actions/deploy-pages@v4`

No `gh-pages` branch; deployment is artifact-based.

## One-Time Repository Setting

Settings → Pages → Source: **GitHub Actions** (required once before the first successful deploy).

## Local Preview

A `composer docs` script in `composer.json`:

```json
"docs": "pip install -r docs/requirements.txt && mkdocs serve"
```

Serves the site at `http://127.0.0.1:8000` with live reload. Requires Python 3.12+ on the developer machine; the prerequisite is mentioned in the README's "Working on the docs" subsection (see below).

## README Trim

Net effect: README drops from ~150 lines to ~30. Final README structure:

1. Title + one-paragraph description
2. Documentation link (`https://tredmann.github.io/repahead/`) as the first call-to-action
3. Docker quick-start block (10 lines, same as today)
4. "Working on the docs" — one-liner pointing at `composer docs`
5. Link to `docs/superpowers/specs/2026-05-06-composer-server-design.md` for contributors

Removed from README and moved to the site:
- Folder layout for ZIPs section
- Endpoints table
- Consumer setup section
- Any deeper configuration commentary

## Out of Scope

- Multi-version docs (`mike`) — not justified for a single-binary project; revisit if tagged-release versioning becomes important.
- Custom domain — supported by adding a `docs/CNAME` file later; not part of this change.
- Architecture / contributor documentation on the site — contributors continue to read `CLAUDE.md`, `CONTEXT.md`, and `docs/superpowers/` directly in the repo.
- Search analytics, comments, blog plugin — overkill for this content size.

## File Inventory

New:
- `mkdocs.yml`
- `docs/requirements.txt`
- `docs/index.md`
- `docs/installation.md`
- `docs/configuration.md`
- `docs/publishing.md`
- `docs/consuming.md`
- `docs/endpoints.md`
- `docs/troubleshooting.md`
- `.github/workflows/docs.yml`

Modified:
- `README.md` (trimmed)
- `composer.json` (add `docs` script)
