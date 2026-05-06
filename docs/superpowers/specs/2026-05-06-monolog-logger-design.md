---
title: Replace custom StderrLogger with Monolog + JsonFormatter
date: 2026-05-06
status: design
---

# Monolog Logger — Design

## Goal

Drop the home-grown `RepAhead\StderrLogger` and use `monolog/monolog` instead,
configured to emit JSON-per-line records to `php://stderr` via Monolog's
`JsonFormatter`. The change is a straight implementation swap — every consumer
(controller, ZIP-metadata reader, packages-JSON builder, exception handler)
already takes `Psr\Log\LoggerInterface` and stays untouched.

## Why

- The custom logger reimplements PSR-3 placeholder interpolation, level
  formatting, and context serialization. Monolog has all three, plus
  formatters, processors, and handlers we'd otherwise need to write if log
  output evolves (e.g. per-message structured fields, redaction).
- JSON-per-line is parseable by every log aggregator (Datadog, Loki,
  Cloudwatch, Vector). The custom logger's bracket-prefixed format is
  human-readable but not directly ingestible.
- Removing the custom class eliminates ~45 lines of code we maintain.

## Non-goals

- No multi-handler pipelines, no log-rotation, no Elastic/Sentry handlers,
  no async batched delivery, no env-configurable log level. v1 is a single
  stderr stream at level Debug (everything passes).
- No changes to call sites — they are PSR-3 already.
- No changes to tests — they pass `Psr\Log\NullLogger` and continue to.

## What changes

### 1. New dependency

Add `monolog/monolog ^3.0` to `composer require` (production dep, since
`public/index.php` constructs the logger).

### 2. `public/index.php`

Replace:

```php
use RepAhead\StderrLogger;
…
$logger = new StderrLogger();
```

with:

```php
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
…
$handler = new StreamHandler('php://stderr', Level::Debug);
$handler->setFormatter(new JsonFormatter());
$logger = new Logger('repahead');
$logger->pushHandler($handler);
```

`Level::Debug` lets every log call through; the four log call sites in the
codebase (`$logger->warning()` in `PackagesJson`, `ZipMetadata`; `$logger->error()`
in `Controller`, `SafeJsonStrategy`) all sit at WARNING/ERROR and pass either
way. `Debug` is the conservative default — if we add `info`/`debug` calls
later, they'll show up automatically.

### 3. Delete `app/StderrLogger.php`

The file becomes dead code. `git rm` it.

### 4. No other changes

The downstream classes already take `Psr\Log\LoggerInterface`. The
constructor signatures in `App::router(…, ?LoggerInterface $logger = null)`,
`Controller(…, LoggerInterface $logger = new NullLogger())`,
`ZipMetadata(LoggerInterface $logger = new NullLogger())`, and
`PackagesJson(LoggerInterface $logger)` stay identical.

Tests construct loggers via `new NullLogger()` from `psr/log` and never
referenced `StderrLogger` directly, so no test edits are required.

## Output format

Monolog's default `JsonFormatter` emits one JSON object per record,
newline-terminated, with these fields:

```json
{
  "message": "Skipping ZIP: corrupt archive",
  "context": {"path": "acme/billing/1.2.0.zip"},
  "level": 300,
  "level_name": "WARNING",
  "channel": "repahead",
  "datetime": "2026-05-06T22:00:00.123456+00:00",
  "extra": {}
}
```

The `level` integer is Monolog's RFC-5424-aligned numeric level (300 =
WARNING, 400 = ERROR, etc.). The `channel` is the constructor argument
(`repahead`).

## Channel name

`repahead`. Mirrors the PHP namespace and the repository directory.

## Risks and failure modes

- **PSR-3 contract differences.** Monolog 3 implements PSR-3 strictly. Our
  call sites already use PSR-3 placeholder syntax (string + array context),
  so behaviour is unchanged. Nothing to verify in code; the test suite
  exercises every call site indirectly.
- **Stream-open failure.** If `php://stderr` cannot be opened (e.g. CGI mode
  with stderr redirected away), Monolog's `StreamHandler` throws on first
  log call. This is no worse than the old `fwrite(STDERR, …)` which would
  silently fail. Out of scope for v1.
- **Output volume.** JSON is more verbose than the old line format
  (~3-4× more bytes per record). Negligible for our use (a handful of log
  lines per request, only when something is wrong).

## Verification

The standard quality pipeline covers it:

- `composer rector` — confirms no follow-on refactorings.
- `composer pint`  — formats the new lines.
- `composer test`  — 55 tests still green; tests use `NullLogger` so they
  don't exercise Monolog, but they prove autoload is intact and no consumer
  broke.
- `composer stan`  — confirms type compatibility (Monolog's `Logger`
  implements `Psr\Log\LoggerInterface`, so passing it where the call sites
  accept `LoggerInterface` is type-safe).

A one-shot manual check confirms the actual JSON output:

```bash
php -r 'require "vendor/autoload.php";
$h = new Monolog\Handler\StreamHandler("php://stderr", Monolog\Level::Debug);
$h->setFormatter(new Monolog\Formatter\JsonFormatter());
$l = new Monolog\Logger("repahead");
$l->pushHandler($h);
$l->warning("Skipping ZIP: corrupt archive", ["path" => "acme/billing/1.2.0.zip"]);'
```

Expected: a single JSON line on stderr with the shape shown above.
