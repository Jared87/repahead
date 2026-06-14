# Consuming

Use repahead as a Composer repository from any project.

## Configure the consuming project

In the project that needs the private packages:

```bash
composer config repositories.private composer https://composer.your-domain.com
composer config http-basic.composer.your-domain.com ci <password>
composer require acme/billing:^1.0
```

The first command adds a `repositories` entry to `composer.json`:

```json
{
  "repositories": {
    "private": {
      "type": "composer",
      "url": "https://composer.your-domain.com"
    }
  }
}
```

The second stores the basic-auth credentials in `auth.json` (which Composer keeps separate from `composer.json` so credentials never end up in version control).

## Authentication in CI

For CI environments, prefer an env var over a checked-in `auth.json`:

```bash
export COMPOSER_AUTH='{"http-basic":{"composer.your-domain.com":{"username":"ci","password":"'"$COMPOSER_PASS"'"}}}'
composer install
```

This lets you rotate the password through your CI secret store without touching the repository.

## Troubleshooting first install

If `composer require` fails:

- **401 Unauthorized** — confirm the username / password match `AUTH_USER` / `AUTH_PASS` on the server. See [Troubleshooting → 401 Unauthorized](troubleshooting.md#401-unauthorized).
- **Package not found** — confirm the ZIP is at the right path in Storage and call `POST /rebuild` if the TTL has not expired. See [Publishing](publishing.md).
