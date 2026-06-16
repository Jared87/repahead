# Installation

## Docker (recommended)

repahead ships as a Docker image: [`tredmann/repahead`](https://hub.docker.com/r/tredmann/repahead).

### Using docker compose

```bash
cp .env.example .env
# edit AUTH_PASS at minimum
AUTH_PASS=$(grep AUTH_PASS .env | cut -d= -f2) docker compose up -d --build
```

The service listens on `http://localhost:8080`. The image bakes in `HEALTHCHECK curl -sf http://localhost:8080/health` every 30 seconds.

### Using `docker run`

Minimal — only `AUTH_PASS` is required:

```bash
docker run -d -p 8080:8080 -e AUTH_PASS=secret tredmann/repahead
```

Production — set `APP_BASE_URL` so Dist URLs resolve correctly, and mount a volume for ZIPs:

```bash
docker run -d \
  -p 8080:8080 \
  -e AUTH_PASS=secret \
  -e APP_BASE_URL=https://composer.your-domain.com \
  -v /path/to/zips:/var/www/html/zips \
  tredmann/repahead
```

## Local PHP

Requires PHP 8.5+ with extensions `fileinfo`, `json`, `zip`, plus [Composer](https://getcomposer.org/).

```bash
composer install
cp .env.example .env
php -S 127.0.0.1:8080 -t public
```

See [Configuration](configuration.md) for the full environment-variable reference.
