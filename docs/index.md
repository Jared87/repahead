# repahead

A small PHP service that exposes a private [Composer](https://getcomposer.org/) (Packagist-compatible) repository. Publishers drop Release ZIPs into Storage; the service builds and serves the Index over HTTP with basic auth.

Storage is pluggable via [Flysystem](https://flysystem.thephpleague.com/): local disk or S3.

## In 30 seconds

```bash
cp .env.example .env  # set AUTH_PASS
AUTH_PASS=$(grep AUTH_PASS .env | cut -d= -f2) docker compose up -d --build
```

Drop a Release ZIP and refresh the Index:

```bash
docker compose cp ./acme-billing-1.2.0.zip composer:/var/www/html/zips/acme/billing/1.2.0.zip
curl -u ci:secret -X POST http://localhost:8080/rebuild
```

## Where to next

- **[Installation](installation.md)** — Docker or local PHP setup.
- **[Configuration](configuration.md)** — Environment variables, storage backends, auth.
- **[Publishing](publishing.md)** — How to add Releases.
- **[Consuming](consuming.md)** — Use the repository from a Composer project.
- **[Endpoints](endpoints.md)** — Full HTTP reference.
- **[Troubleshooting](troubleshooting.md)** — Common issues.
