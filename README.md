# Private Composer Server

A small PHP service that exposes a private Composer (Packagist-compatible) repository. Publishers drop Release ZIPs into Storage; the service builds and serves the Index. Storage is pluggable via Flysystem (local disk or S3).

**Full documentation:** <https://tredmann.github.io/repahead/>

## Quick start (Docker)

```bash
cp .env.example .env
# edit AUTH_PASS at minimum
AUTH_PASS=$(grep AUTH_PASS .env | cut -d= -f2) docker compose up -d --build
```

The service listens on `http://localhost:8080`.

See the [installation guide](https://tredmann.github.io/repahead/installation/) for `docker run`, local PHP setup, and production configuration.

## Working on the docs

Local preview at <http://127.0.0.1:8000> with live reload (requires Python 3.12+):

```bash
composer docs
```

## For contributors

- Domain glossary: [`CONTEXT.md`](CONTEXT.md)
- Architecture decisions: [`docs/adr/`](docs/adr/)
- Full design spec: [`docs/superpowers/specs/2026-05-06-composer-server-design.md`](docs/superpowers/specs/2026-05-06-composer-server-design.md)
