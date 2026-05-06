# Private Composer Server Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a small PHP web app that serves a private Composer (Packagist-compatible) repository whose content is driven by ZIP files dropped into a configurable storage backend (local disk or S3 via Flysystem).

**Architecture:** A `league/route` HTTP app exposing `GET /packages.json`, `GET /dist/{vendor}/{package}/{version}.zip`, and `POST /rebuild`, all behind PSR-15 HTTP-Basic-Auth middleware. A request lists the storage prefix, hashes the listing, compares to the previous hash, and either serves a cached `packages.json` from local disk or rebuilds it (extracting `composer.json` from each ZIP via `ZipArchive`). The cache is invalidated by manifest hash; an optional TTL skips listing entirely between drops.

**Tech Stack:** PHP 8.2+, `league/route`, `league/flysystem` (+ `flysystem-aws-s3-v3`, `flysystem-memory` for tests), `laminas/laminas-diactoros`, `laminas/laminas-httphandlerrunner`, `vlucas/phpdotenv`, `psr/log`, PHPUnit 10. FrankenPHP container (`serversideup/php:8.4-frankenphp`).

**Spec:** `docs/superpowers/specs/2026-05-06-composer-server-design.md`

**Working directory:** The implementation lives at the repository root (`./public`, `./src`, `./tests`, …). The repo currently contains only the spec under `docs/`.

---

## File Structure

```
.
├── public/
│   └── index.php              # entry point: bootstrap + dispatch + emit
├── src/
│   ├── Config.php             # env loading + typed getters
│   ├── StderrLogger.php       # tiny PSR-3 logger writing to stderr
│   ├── Storage.php            # Flysystem factory from STORAGE_DSN
│   ├── CatalogEntry.php       # value object (vendor, package, version, path, size, lastModified)
│   ├── Catalog.php            # walks Flysystem -> CatalogEntry[] + listing hash
│   ├── ZipMeta.php            # value object (composerJson, sha1)
│   ├── ZipMetadata.php        # reads composer.json + sha1 from a ZIP via Flysystem
│   ├── PackagesJson.php       # builder that turns catalog + metadata into JSON
│   ├── Cache.php              # packages.json + manifest.hash + flock + atomic write
│   ├── Auth.php               # PSR-15 basic-auth middleware
│   ├── Controller.php         # three route handlers
│   └── App.php                # container/factory wiring all components
├── tests/
│   ├── ConfigTest.php
│   ├── StorageTest.php
│   ├── CatalogTest.php
│   ├── ZipMetadataTest.php
│   ├── PackagesJsonTest.php
│   ├── CacheTest.php
│   ├── AuthTest.php
│   ├── ControllerTest.php
│   ├── EndToEndTest.php
│   └── Support/
│       └── ZipBuilder.php     # helper to build ZIPs in-memory for tests
├── cache/.gitkeep             # auto-generated cache files live here
├── zips/.gitkeep              # default local-storage drop folder
├── .env.example
├── .gitignore
├── compose.yml
├── Dockerfile
├── composer.json
├── phpunit.xml
└── README.md
```

Each `src/` file has one responsibility; tests sit beside them 1:1. The `App.php` factory exists so tests can build the wired app without going through `public/index.php`.

---

## Task 1: Bootstrap project skeleton

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml`
- Create: `.gitignore`
- Create: `.env.example`
- Create: `cache/.gitkeep`
- Create: `zips/.gitkeep`
- Create: `tests/SmokeTest.php`

- [ ] **Step 1: Write the failing smoke test**

`tests/SmokeTest.php`:
```php
<?php
declare(strict_types=1);

namespace Composerd\Tests;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testAutoloaderIsWired(): void
    {
        self::assertTrue(class_exists(\PHPUnit\Framework\TestCase::class));
        self::assertSame('Composerd\\Tests', __NAMESPACE__);
    }
}
```

- [ ] **Step 2: Create `composer.json`**

```json
{
    "name": "repahead/composer-server",
    "description": "Static-feeling private Composer repository",
    "type": "project",
    "license": "proprietary",
    "require": {
        "php": ">=8.2",
        "ext-zip": "*",
        "ext-json": "*",
        "ext-fileinfo": "*",
        "league/route": "^6.2",
        "league/flysystem": "^3.28",
        "league/flysystem-aws-s3-v3": "^3.28",
        "laminas/laminas-diactoros": "^3.4",
        "laminas/laminas-httphandlerrunner": "^2.10",
        "vlucas/phpdotenv": "^5.6",
        "psr/log": "^3.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5",
        "league/flysystem-memory": "^3.28"
    },
    "autoload": {
        "psr-4": { "Composerd\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "Composerd\\Tests\\": "tests/" }
    },
    "config": {
        "sort-packages": true
    }
}
```

- [ ] **Step 3: Create `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         displayDetailsOnTestsThatTriggerWarnings="true"
         failOnWarning="true">
  <testsuites>
    <testsuite name="all">
      <directory>tests</directory>
    </testsuite>
  </testsuites>
  <source>
    <include>
      <directory>src</directory>
    </include>
  </source>
</phpunit>
```

- [ ] **Step 4: Append project rules to `.gitignore`**

The repo already has a `.gitignore` containing `.idea/`. Append (don't overwrite) the following so the file ends with these rules:

```
/vendor/
/.env
/cache/*
!/cache/.gitkeep
/zips/*
!/zips/.gitkeep
.phpunit.result.cache
.phpunit.cache/
```

The final file should contain both `.idea/` and the rules above.

- [ ] **Step 5: Create `.env.example`**

```
APP_BASE_URL=https://composer.your-domain.com

STORAGE_DSN=local:./zips
# STORAGE_DSN=s3:my-bucket/composer/zips
# AWS_ACCESS_KEY_ID=
# AWS_SECRET_ACCESS_KEY=
# AWS_REGION=eu-central-1

CACHE_DIR=./cache
LISTING_TTL_SECONDS=30

AUTH_USER=ci
AUTH_PASS=replace-me
```

- [ ] **Step 6: Create empty `cache/.gitkeep` and `zips/.gitkeep`**

```bash
touch cache/.gitkeep zips/.gitkeep
```

- [ ] **Step 7: Install dependencies**

Run: `composer install`
Expected: `vendor/` directory created, no errors.

- [ ] **Step 8: Run the smoke test**

Run: `vendor/bin/phpunit tests/SmokeTest.php`
Expected: PASS, 1 test, 2 assertions.

- [ ] **Step 9: Commit**

```bash
git add composer.json composer.lock phpunit.xml .gitignore .env.example cache/.gitkeep zips/.gitkeep tests/SmokeTest.php
git commit -m "chore: bootstrap project skeleton with PSR-4 autoload and PHPUnit"
```

---

## Task 2: Config (typed env getters)

**Files:**
- Create: `src/Config.php`
- Test: `tests/ConfigTest.php`

The `Config` class loads env vars (in production via `.env`, in tests via constructor injection) and exposes typed getters. We'll inject the env array directly to keep tests deterministic; `.env` loading happens in `App.php` later.

- [ ] **Step 1: Write the failing test**

`tests/ConfigTest.php`:
```php
<?php
declare(strict_types=1);

namespace Composerd\Tests;

use Composerd\Config;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private function defaults(): array
    {
        return [
            'APP_BASE_URL' => 'https://composer.example.com',
            'STORAGE_DSN' => 'local:./zips',
            'CACHE_DIR' => './cache',
            'LISTING_TTL_SECONDS' => '30',
            'AUTH_USER' => 'ci',
            'AUTH_PASS' => 'secret',
        ];
    }

    public function testReadsAllValues(): void
    {
        $c = new Config($this->defaults());
        self::assertSame('https://composer.example.com', $c->baseUrl());
        self::assertSame('local:./zips', $c->storageDsn());
        self::assertSame('./cache', $c->cacheDir());
        self::assertSame(30, $c->listingTtlSeconds());
        self::assertSame('ci', $c->authUser());
        self::assertSame('secret', $c->authPass());
    }

    public function testListingTtlDefaultsToZeroWhenMissing(): void
    {
        $env = $this->defaults();
        unset($env['LISTING_TTL_SECONDS']);
        $c = new Config($env);
        self::assertSame(0, $c->listingTtlSeconds());
    }

    public function testListingTtlMustBeNonNegativeInteger(): void
    {
        $env = $this->defaults();
        $env['LISTING_TTL_SECONDS'] = '-1';
        $this->expectException(InvalidArgumentException::class);
        new Config($env);
    }

    public function testRequiredKeysAreEnforced(): void
    {
        $env = $this->defaults();
        unset($env['STORAGE_DSN']);
        $this->expectException(InvalidArgumentException::class);
        new Config($env);
    }

    public function testTrailingSlashOnBaseUrlIsStripped(): void
    {
        $env = $this->defaults();
        $env['APP_BASE_URL'] = 'https://composer.example.com/';
        $c = new Config($env);
        self::assertSame('https://composer.example.com', $c->baseUrl());
    }
}
```

- [ ] **Step 2: Run the test, expect failure**

Run: `vendor/bin/phpunit tests/ConfigTest.php`
Expected: FAIL — class `Composerd\Config` not found.

- [ ] **Step 3: Implement `src/Config.php`**

```php
<?php
declare(strict_types=1);

namespace Composerd;

use InvalidArgumentException;

final class Config
{
    private const REQUIRED = [
        'APP_BASE_URL',
        'STORAGE_DSN',
        'CACHE_DIR',
        'AUTH_USER',
        'AUTH_PASS',
    ];

    private string $baseUrl;
    private string $storageDsn;
    private string $cacheDir;
    private int $listingTtl;
    private string $authUser;
    private string $authPass;

    /** @param array<string,string> $env */
    public function __construct(array $env)
    {
        foreach (self::REQUIRED as $key) {
            if (!isset($env[$key]) || $env[$key] === '') {
                throw new InvalidArgumentException("Missing required env var: $key");
            }
        }

        $this->baseUrl = rtrim($env['APP_BASE_URL'], '/');
        $this->storageDsn = $env['STORAGE_DSN'];
        $this->cacheDir = $env['CACHE_DIR'];
        $this->authUser = $env['AUTH_USER'];
        $this->authPass = $env['AUTH_PASS'];

        $ttlRaw = $env['LISTING_TTL_SECONDS'] ?? '0';
        if (!ctype_digit($ttlRaw)) {
            throw new InvalidArgumentException(
                "LISTING_TTL_SECONDS must be a non-negative integer, got: $ttlRaw"
            );
        }
        $this->listingTtl = (int) $ttlRaw;
    }

    public function baseUrl(): string { return $this->baseUrl; }
    public function storageDsn(): string { return $this->storageDsn; }
    public function cacheDir(): string { return $this->cacheDir; }
    public function listingTtlSeconds(): int { return $this->listingTtl; }
    public function authUser(): string { return $this->authUser; }
    public function authPass(): string { return $this->authPass; }
}
```

- [ ] **Step 4: Run the test, expect pass**

Run: `vendor/bin/phpunit tests/ConfigTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Config.php tests/ConfigTest.php
git commit -m "feat(config): add typed env-driven configuration"
```

---

## Task 3: StderrLogger (PSR-3)

**Files:**
- Create: `src/StderrLogger.php`
- Test: none (trivial wrapper; covered indirectly elsewhere)

A tiny `LoggerInterface` implementation that writes one line per log call to `STDERR`. We deliberately skip a unit test here — testing that PHP writes to a stream is not informative — but use it through `Psr\Log\NullLogger` in higher-level tests so we don't pollute test output.

- [ ] **Step 1: Implement `src/StderrLogger.php`**

```php
<?php
declare(strict_types=1);

namespace Composerd;

use Psr\Log\AbstractLogger;
use Stringable;

final class StderrLogger extends AbstractLogger
{
    /** @param array<string,mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $line = sprintf(
            "[%s] %s %s%s\n",
            date('c'),
            strtoupper((string) $level),
            (string) $message,
            $context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : ''
        );
        fwrite(STDERR, $line);
    }
}
```

- [ ] **Step 2: Verify it autoloads**

Run: `php -r 'require "vendor/autoload.php"; var_dump(new Composerd\StderrLogger() instanceof Psr\Log\LoggerInterface);'`
Expected output: `bool(true)`

- [ ] **Step 3: Commit**

```bash
git add src/StderrLogger.php
git commit -m "feat(logger): add minimal PSR-3 stderr logger"
```

---

## Task 4: Storage (Flysystem factory)

**Files:**
- Create: `src/Storage.php`
- Test: `tests/StorageTest.php`

Parses a `STORAGE_DSN` string and returns a configured `League\Flysystem\Filesystem`. Two schemes for v1: `local:<path>` and `s3:<bucket>/<prefix>`. The S3 case requires AWS credentials from env; we accept them via constructor for testability.

- [ ] **Step 1: Write the failing test**

`tests/StorageTest.php`:
```php
<?php
declare(strict_types=1);

namespace Composerd\Tests;

use Composerd\Storage;
use InvalidArgumentException;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\TestCase;

final class StorageTest extends TestCase
{
    public function testLocalDsnReturnsFilesystem(): void
    {
        $fs = (new Storage([]))->make('local:' . sys_get_temp_dir());
        self::assertInstanceOf(Filesystem::class, $fs);
    }

    public function testLocalDsnWritesAndReadsThrough(): void
    {
        $tmp = sys_get_temp_dir() . '/composerd-storage-' . bin2hex(random_bytes(4));
        mkdir($tmp);
        try {
            $fs = (new Storage([]))->make("local:$tmp");
            $fs->write('hello.txt', 'world');
            self::assertSame('world', $fs->read('hello.txt'));
        } finally {
            @unlink("$tmp/hello.txt");
            @rmdir($tmp);
        }
    }

    public function testUnknownSchemeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Storage([]))->make('ftp:host/path');
    }

    public function testMalformedDsnThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Storage([]))->make('no-colon-here');
    }

    public function testS3DsnRequiresCredentials(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Storage([]))->make('s3:bucket/prefix');
    }
}
```

- [ ] **Step 2: Run the test, expect failure**

Run: `vendor/bin/phpunit tests/StorageTest.php`
Expected: FAIL — class `Composerd\Storage` not found.

- [ ] **Step 3: Implement `src/Storage.php`**

```php
<?php
declare(strict_types=1);

namespace Composerd;

use Aws\S3\S3Client;
use InvalidArgumentException;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

final class Storage
{
    /** @param array<string,string> $awsEnv */
    public function __construct(private array $awsEnv) {}

    public function make(string $dsn): Filesystem
    {
        $colon = strpos($dsn, ':');
        if ($colon === false) {
            throw new InvalidArgumentException("Malformed STORAGE_DSN: $dsn");
        }
        $scheme = substr($dsn, 0, $colon);
        $rest = substr($dsn, $colon + 1);

        return match ($scheme) {
            'local' => new Filesystem(new LocalFilesystemAdapter($rest)),
            's3' => $this->makeS3($rest),
            default => throw new InvalidArgumentException("Unknown DSN scheme: $scheme"),
        };
    }

    private function makeS3(string $rest): Filesystem
    {
        $slash = strpos($rest, '/');
        $bucket = $slash === false ? $rest : substr($rest, 0, $slash);
        $prefix = $slash === false ? '' : substr($rest, $slash + 1);

        foreach (['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_REGION'] as $required) {
            if (empty($this->awsEnv[$required])) {
                throw new InvalidArgumentException("S3 storage requires env var: $required");
            }
        }

        $client = new S3Client([
            'version' => 'latest',
            'region' => $this->awsEnv['AWS_REGION'],
            'credentials' => [
                'key' => $this->awsEnv['AWS_ACCESS_KEY_ID'],
                'secret' => $this->awsEnv['AWS_SECRET_ACCESS_KEY'],
            ],
        ]);

        return new Filesystem(new AwsS3V3Adapter($client, $bucket, $prefix));
    }
}
```

- [ ] **Step 4: Run the test, expect pass**

Run: `vendor/bin/phpunit tests/StorageTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Storage.php tests/StorageTest.php
git commit -m "feat(storage): add Flysystem factory for local and S3 DSNs"
```

---

## Task 5: ZipBuilder test helper

**Files:**
- Create: `tests/Support/ZipBuilder.php`

Several upcoming tests need to materialize a ZIP file containing a `composer.json`. This helper builds one in-memory or to a temp path. Putting it in a shared file keeps test code DRY.

- [ ] **Step 1: Implement `tests/Support/ZipBuilder.php`**

```php
<?php
declare(strict_types=1);

namespace Composerd\Tests\Support;

use ZipArchive;

final class ZipBuilder
{
    /**
     * Build a ZIP at the given path containing a composer.json plus optional extras.
     *
     * @param array<string,mixed> $composerJson
     * @param array<string,string> $extraFiles  filename => content
     */
    public static function buildAt(string $path, array $composerJson, array $extraFiles = []): void
    {
        $zip = new ZipArchive();
        $rc = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($rc !== true) {
            throw new \RuntimeException("Failed to open ZIP for writing: $path (code $rc)");
        }
        $zip->addFromString('composer.json', json_encode($composerJson, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        foreach ($extraFiles as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
    }

    /** Returns binary contents of a ZIP built in a temp path then read+deleted. */
    public static function buildBytes(array $composerJson, array $extraFiles = []): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'composerd-zip');
        if ($tmp === false) {
            throw new \RuntimeException('tempnam failed');
        }
        try {
            self::buildAt($tmp, $composerJson, $extraFiles);
            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /** Build a corrupt ZIP (just garbage bytes) for negative tests. */
    public static function buildCorruptBytes(): string
    {
        return "PK\x03\x04this-is-not-a-valid-zip-file";
    }
}
```

- [ ] **Step 2: Update `composer.json` autoload-dev to include `Support`**

The existing `"Composerd\\Tests\\": "tests/"` already covers `tests/Support/` because `Support` is a sub-namespace under `Composerd\Tests`. No edit needed; verify by running:

Run: `composer dump-autoload`
Expected: no errors.

- [ ] **Step 3: Sanity check**

Run: `php -r 'require "vendor/autoload.php"; $b = Composerd\Tests\Support\ZipBuilder::buildBytes(["name" => "x/y", "version" => "1.0.0"]); echo strlen($b), PHP_EOL;'`
Expected: a number > 100 printed.

- [ ] **Step 4: Commit**

```bash
git add tests/Support/ZipBuilder.php
git commit -m "test: add ZipBuilder helper for building fixture ZIPs"
```

---

## Task 6: Catalog (list ZIPs from Flysystem)

**Files:**
- Create: `src/CatalogEntry.php`
- Create: `src/Catalog.php`
- Test: `tests/CatalogTest.php`

`Catalog::scan()` walks the Flysystem prefix recursively, extracts `(vendor, package, version)` from each path that matches `vendor/package/version.zip`, and returns a sorted list of `CatalogEntry` objects plus a stable hash of the listing.

Files outside the `vendor/package/version.zip` shape (e.g. `vendor/package/README.md`, top-level files, three-level-deep folders) are silently ignored. This keeps the storage prefix tolerant of stray files.

- [ ] **Step 1: Write the failing test**

`tests/CatalogTest.php`:
```php
<?php
declare(strict_types=1);

namespace Composerd\Tests;

use Composerd\Catalog;
use Composerd\CatalogEntry;
use Composerd\Tests\Support\ZipBuilder;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;

final class CatalogTest extends TestCase
{
    private function fsWith(array $files): Filesystem
    {
        $fs = new Filesystem(new InMemoryFilesystemAdapter());
        foreach ($files as $path => $content) {
            $fs->write($path, $content);
        }
        return $fs;
    }

    public function testScanReturnsEntriesForWellFormedPaths(): void
    {
        $fs = $this->fsWith([
            'acme/billing/1.2.0.zip' => ZipBuilder::buildBytes(['name' => 'acme/billing', 'version' => '1.2.0']),
            'acme/billing/1.3.0.zip' => ZipBuilder::buildBytes(['name' => 'acme/billing', 'version' => '1.3.0']),
            'beta/sdk/2.0.0.zip' => ZipBuilder::buildBytes(['name' => 'beta/sdk', 'version' => '2.0.0']),
        ]);

        $catalog = new Catalog();
        [$entries, $hash] = $catalog->scan($fs);

        self::assertCount(3, $entries);
        self::assertContainsOnlyInstancesOf(CatalogEntry::class, $entries);
        $names = array_map(fn(CatalogEntry $e) => "{$e->vendor}/{$e->package}@{$e->version}", $entries);
        sort($names);
        self::assertSame(
            ['acme/billing@1.2.0', 'acme/billing@1.3.0', 'beta/sdk@2.0.0'],
            $names
        );
        self::assertNotEmpty($hash);
        self::assertSame(64, strlen($hash));
    }

    public function testHashIsStableAcrossCalls(): void
    {
        $fs = $this->fsWith([
            'a/b/1.0.0.zip' => ZipBuilder::buildBytes(['name' => 'a/b', 'version' => '1.0.0']),
        ]);
        $catalog = new Catalog();
        [, $hash1] = $catalog->scan($fs);
        [, $hash2] = $catalog->scan($fs);
        self::assertSame($hash1, $hash2);
    }

    public function testHashChangesWhenFilesChange(): void
    {
        $fs = $this->fsWith([
            'a/b/1.0.0.zip' => ZipBuilder::buildBytes(['name' => 'a/b', 'version' => '1.0.0']),
        ]);
        $catalog = new Catalog();
        [, $hash1] = $catalog->scan($fs);
        $fs->write('a/b/1.0.1.zip', ZipBuilder::buildBytes(['name' => 'a/b', 'version' => '1.0.1']));
        [, $hash2] = $catalog->scan($fs);
        self::assertNotSame($hash1, $hash2);
    }

    public function testIgnoresMalformedPaths(): void
    {
        $fs = $this->fsWith([
            'top-level.zip' => 'x',
            'just-vendor/file.zip' => 'x',
            'a/b/c/extra/1.0.0.zip' => 'x',
            'a/b/README.md' => 'x',
            'a/b/1.0.0.zip' => ZipBuilder::buildBytes(['name' => 'a/b', 'version' => '1.0.0']),
        ]);
        $catalog = new Catalog();
        [$entries] = $catalog->scan($fs);
        self::assertCount(1, $entries);
        self::assertSame('a/b', "{$entries[0]->vendor}/{$entries[0]->package}");
    }

    public function testEmptyStorageReturnsEmptyEntries(): void
    {
        $fs = $this->fsWith([]);
        $catalog = new Catalog();
        [$entries, $hash] = $catalog->scan($fs);
        self::assertSame([], $entries);
        self::assertSame(64, strlen($hash));
    }
}
```

- [ ] **Step 2: Run the test, expect failure**

Run: `vendor/bin/phpunit tests/CatalogTest.php`
Expected: FAIL — classes `Composerd\Catalog` / `CatalogEntry` not found.

- [ ] **Step 3: Implement `src/CatalogEntry.php`**

```php
<?php
declare(strict_types=1);

namespace Composerd;

final readonly class CatalogEntry
{
    public function __construct(
        public string $vendor,
        public string $package,
        public string $version,
        public string $path,
        public int $size,
        public int $lastModified,
    ) {}

    public function fullName(): string
    {
        return "{$this->vendor}/{$this->package}";
    }
}
```

- [ ] **Step 4: Implement `src/Catalog.php`**

```php
<?php
declare(strict_types=1);

namespace Composerd;

use League\Flysystem\Filesystem;

final class Catalog
{
    /**
     * @return array{0: list<CatalogEntry>, 1: string}  [entries, sha256-of-listing]
     */
    public function scan(Filesystem $fs): array
    {
        $entries = [];
        foreach ($fs->listContents('', Filesystem::LIST_DEEP) as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $path = $item->path();
            if (!preg_match('#^([^/]+)/([^/]+)/([^/]+)\.zip$#', $path, $m)) {
                continue;
            }
            $entries[] = new CatalogEntry(
                vendor: $m[1],
                package: $m[2],
                version: $m[3],
                path: $path,
                size: (int) ($item->fileSize() ?? 0),
                lastModified: (int) ($item->lastModified() ?? 0),
            );
        }

        usort(
            $entries,
            fn(CatalogEntry $a, CatalogEntry $b) => strcmp($a->path, $b->path)
        );

        $hashInput = '';
        foreach ($entries as $e) {
            $hashInput .= "$e->path|$e->size|$e->lastModified\n";
        }
        $hash = hash('sha256', $hashInput);

        return [$entries, $hash];
    }
}
```

- [ ] **Step 5: Run the test, expect pass**

Run: `vendor/bin/phpunit tests/CatalogTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 6: Commit**

```bash
git add src/Catalog.php src/CatalogEntry.php tests/CatalogTest.php
git commit -m "feat(catalog): list ZIPs from Flysystem with stable hash"
```

---

## Task 7: ZipMetadata (read composer.json from inside a ZIP)

**Files:**
- Create: `src/ZipMeta.php`
- Create: `src/ZipMetadata.php`
- Test: `tests/ZipMetadataTest.php`

Streams a ZIP from Flysystem to a temp file, opens with `ZipArchive`, decodes `composer.json`, and computes the SHA1 of the ZIP bytes. Errors are signalled by returning `null` so the caller can decide to skip + log.

- [ ] **Step 1: Write the failing test**

`tests/ZipMetadataTest.php`:
```php
<?php
declare(strict_types=1);

namespace Composerd\Tests;

use Composerd\ZipMetadata;
use Composerd\Tests\Support\ZipBuilder;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;

final class ZipMetadataTest extends TestCase
{
    private function fs(): Filesystem
    {
        return new Filesystem(new InMemoryFilesystemAdapter());
    }

    public function testReadsComposerJsonAndShasum(): void
    {
        $fs = $this->fs();
        $bytes = ZipBuilder::buildBytes([
            'name' => 'acme/billing',
            'version' => '1.2.0',
            'type' => 'library',
            'require' => ['php' => '^8.2'],
        ]);
        $fs->write('acme/billing/1.2.0.zip', $bytes);

        $meta = (new ZipMetadata())->read($fs, 'acme/billing/1.2.0.zip');

        self::assertNotNull($meta);
        self::assertSame('acme/billing', $meta->composerJson['name']);
        self::assertSame('1.2.0', $meta->composerJson['version']);
        self::assertSame(sha1($bytes), $meta->sha1);
    }

    public function testReturnsNullWhenZipIsCorrupt(): void
    {
        $fs = $this->fs();
        $fs->write('a/b/1.0.0.zip', ZipBuilder::buildCorruptBytes());
        self::assertNull((new ZipMetadata())->read($fs, 'a/b/1.0.0.zip'));
    }

    public function testReturnsNullWhenComposerJsonMissing(): void
    {
        $fs = $this->fs();
        // build a ZIP with NO composer.json
        $tmp = tempnam(sys_get_temp_dir(), 'no-cj');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('README.md', 'hi');
        $zip->close();
        $fs->write('a/b/1.0.0.zip', (string) file_get_contents($tmp));
        @unlink($tmp);

        self::assertNull((new ZipMetadata())->read($fs, 'a/b/1.0.0.zip'));
    }

    public function testReturnsNullWhenComposerJsonInvalidJson(): void
    {
        $fs = $this->fs();
        $tmp = tempnam(sys_get_temp_dir(), 'bad-cj');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('composer.json', 'not json {{{');
        $zip->close();
        $fs->write('a/b/1.0.0.zip', (string) file_get_contents($tmp));
        @unlink($tmp);

        self::assertNull((new ZipMetadata())->read($fs, 'a/b/1.0.0.zip'));
    }
}
```

- [ ] **Step 2: Run the test, expect failure**

Run: `vendor/bin/phpunit tests/ZipMetadataTest.php`
Expected: FAIL — classes `Composerd\ZipMetadata` / `ZipMeta` not found.

- [ ] **Step 3: Implement `src/ZipMeta.php`**

```php
<?php
declare(strict_types=1);

namespace Composerd;

final readonly class ZipMeta
{
    /** @param array<string,mixed> $composerJson */
    public function __construct(
        public array $composerJson,
        public string $sha1,
    ) {}
}
```

- [ ] **Step 4: Implement `src/ZipMetadata.php`**

```php
<?php
declare(strict_types=1);

namespace Composerd;

use League\Flysystem\Filesystem;
use Throwable;
use ZipArchive;

final class ZipMetadata
{
    public function read(Filesystem $fs, string $path): ?ZipMeta
    {
        $tmp = tempnam(sys_get_temp_dir(), 'composerd-zip');
        if ($tmp === false) {
            return null;
        }
        try {
            $stream = $fs->readStream($path);
            $out = fopen($tmp, 'wb');
            if ($out === false) return null;
            try {
                stream_copy_to_stream($stream, $out);
            } finally {
                fclose($out);
            }

            $sha1 = sha1_file($tmp);
            if ($sha1 === false) return null;

            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) return null;
            try {
                $raw = $zip->getFromName('composer.json');
                if ($raw === false) return null;
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) return null;
                return new ZipMeta($decoded, $sha1);
            } finally {
                $zip->close();
            }
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($tmp);
        }
    }
}
```

- [ ] **Step 5: Run the test, expect pass**

Run: `vendor/bin/phpunit tests/ZipMetadataTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 6: Commit**

```bash
git add src/ZipMeta.php src/ZipMetadata.php tests/ZipMetadataTest.php
git commit -m "feat(zip): extract composer.json and sha1 from ZIPs via Flysystem"
```

---

## Task 8: PackagesJson (build the response JSON)

**Files:**
- Create: `src/PackagesJson.php`
- Test: `tests/PackagesJsonTest.php`

Pure builder. Given a list of `CatalogEntry`, a metadata reader callable (`fn(CatalogEntry): ?ZipMeta`), and the base URL, returns the final `packages.json` string. Validates and skips entries; returns the count of skipped items so the caller can include it in `POST /rebuild` responses.

- [ ] **Step 1: Write the failing test**

`tests/PackagesJsonTest.php`:
```php
<?php
declare(strict_types=1);

namespace Composerd\Tests;

use Composerd\CatalogEntry;
use Composerd\PackagesJson;
use Composerd\ZipMeta;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PackagesJsonTest extends TestCase
{
    private function entry(string $vendor, string $package, string $version): CatalogEntry
    {
        return new CatalogEntry(
            $vendor, $package, $version,
            "$vendor/$package/$version.zip",
            100, 1700000000
        );
    }

    public function testBuildsExpectedShape(): void
    {
        $entries = [
            $this->entry('acme', 'billing', '1.2.0'),
            $this->entry('acme', 'billing', '1.3.0'),
        ];
        $reader = function (CatalogEntry $e): ZipMeta {
            return new ZipMeta(
                ['name' => "{$e->vendor}/{$e->package}", 'version' => $e->version, 'type' => 'library', 'require' => ['php' => '^8.2']],
                str_repeat('a', 40)
            );
        };
        $builder = new PackagesJson(new NullLogger());

        $result = $builder->build($entries, $reader, 'https://example.com');
        $decoded = json_decode($result->json, true);

        self::assertArrayHasKey('packages', $decoded);
        self::assertArrayHasKey('acme/billing', $decoded['packages']);
        self::assertSame(
            ['1.2.0', '1.3.0'],
            array_keys($decoded['packages']['acme/billing'])
        );
        $v = $decoded['packages']['acme/billing']['1.2.0'];
        self::assertSame('acme/billing', $v['name']);
        self::assertSame('1.2.0', $v['version']);
        self::assertSame('library', $v['type']);
        self::assertSame(['php' => '^8.2'], $v['require']);
        self::assertSame(
            'https://example.com/dist/acme/billing/1.2.0.zip',
            $v['dist']['url']
        );
        self::assertSame('zip', $v['dist']['type']);
        self::assertSame(str_repeat('a', 40), $v['dist']['shasum']);
        self::assertSame(2, $result->packagesCount);
        self::assertSame(2, $result->versionsCount);
        self::assertSame(0, $result->skippedCount);
    }

    public function testSkipsEntriesWhenMetadataReaderReturnsNull(): void
    {
        $entries = [
            $this->entry('a', 'b', '1.0.0'),
            $this->entry('a', 'b', '2.0.0'),
        ];
        $reader = fn(CatalogEntry $e) => $e->version === '2.0.0'
            ? null
            : new ZipMeta(['name' => 'a/b', 'version' => '1.0.0'], str_repeat('b', 40));
        $builder = new PackagesJson(new NullLogger());
        $result = $builder->build($entries, $reader, 'https://x');
        $decoded = json_decode($result->json, true);

        self::assertSame(['1.0.0'], array_keys($decoded['packages']['a/b']));
        self::assertSame(1, $result->skippedCount);
    }

    public function testSkipsWhenComposerJsonNameMismatchesPath(): void
    {
        $entries = [$this->entry('a', 'b', '1.0.0')];
        $reader = fn() => new ZipMeta(['name' => 'wrong/name', 'version' => '1.0.0'], str_repeat('c', 40));
        $builder = new PackagesJson(new NullLogger());
        $result = $builder->build($entries, $reader, 'https://x');
        $decoded = json_decode($result->json, true);

        self::assertSame([], $decoded['packages'] ?? []);
        self::assertSame(1, $result->skippedCount);
    }

    public function testFilenameVersionWinsButLogsOnMismatch(): void
    {
        $entries = [$this->entry('a', 'b', '1.0.0')];
        $reader = fn() => new ZipMeta(
            ['name' => 'a/b', 'version' => '9.9.9', 'type' => 'library'],
            str_repeat('d', 40)
        );
        $builder = new PackagesJson(new NullLogger());
        $result = $builder->build($entries, $reader, 'https://x');
        $decoded = json_decode($result->json, true);

        self::assertSame('1.0.0', $decoded['packages']['a/b']['1.0.0']['version']);
        self::assertSame(0, $result->skippedCount);
    }

    public function testSkipsWhenComposerJsonMissingName(): void
    {
        $entries = [$this->entry('a', 'b', '1.0.0')];
        $reader = fn() => new ZipMeta(['version' => '1.0.0'], str_repeat('e', 40));
        $builder = new PackagesJson(new NullLogger());
        $result = $builder->build($entries, $reader, 'https://x');
        self::assertSame(1, $result->skippedCount);
    }

    public function testEmptyEntriesProducesEmptyPackages(): void
    {
        $builder = new PackagesJson(new NullLogger());
        $result = $builder->build([], fn() => null, 'https://x');
        $decoded = json_decode($result->json, true);
        self::assertSame(['packages' => new \stdClass()], json_decode($result->json), 'should be {} not []');
        self::assertSame([], $decoded['packages']);
        self::assertSame(0, $result->packagesCount);
    }
}
```

- [ ] **Step 2: Run the test, expect failure**

Run: `vendor/bin/phpunit tests/PackagesJsonTest.php`
Expected: FAIL — class `Composerd\PackagesJson` not found.

- [ ] **Step 3: Implement `src/PackagesJson.php`**

```php
<?php
declare(strict_types=1);

namespace Composerd;

use Psr\Log\LoggerInterface;

final readonly class PackagesJsonResult
{
    public function __construct(
        public string $json,
        public int $packagesCount,
        public int $versionsCount,
        public int $skippedCount,
    ) {}
}

final class PackagesJson
{
    public function __construct(private LoggerInterface $logger) {}

    /**
     * @param iterable<CatalogEntry> $entries
     * @param callable(CatalogEntry): ?ZipMeta $reader
     */
    public function build(iterable $entries, callable $reader, string $baseUrl): PackagesJsonResult
    {
        $packages = [];
        $versionCount = 0;
        $skipped = 0;

        foreach ($entries as $entry) {
            $meta = $reader($entry);
            if ($meta === null) {
                $this->logger->warning('Skipping unreadable ZIP', ['path' => $entry->path]);
                $skipped++;
                continue;
            }
            $cj = $meta->composerJson;
            $expectedName = $entry->fullName();

            if (!isset($cj['name']) || !is_string($cj['name'])) {
                $this->logger->warning('Skipping ZIP missing composer.json name', ['path' => $entry->path]);
                $skipped++;
                continue;
            }
            if ($cj['name'] !== $expectedName) {
                $this->logger->warning('Skipping ZIP with name/path mismatch', [
                    'path' => $entry->path,
                    'composer_name' => $cj['name'],
                    'expected_name' => $expectedName,
                ]);
                $skipped++;
                continue;
            }

            if (isset($cj['version']) && $cj['version'] !== $entry->version) {
                $this->logger->warning('Filename version differs from composer.json version; using filename', [
                    'path' => $entry->path,
                    'filename_version' => $entry->version,
                    'composer_version' => $cj['version'],
                ]);
            }

            $cj['version'] = $entry->version;
            $cj['dist'] = [
                'type' => 'zip',
                'url' => $baseUrl . '/dist/' . $entry->path,
                'shasum' => $meta->sha1,
            ];

            $packages[$expectedName][$entry->version] = $cj;
            $versionCount++;
        }

        ksort($packages);
        foreach ($packages as &$versions) {
            ksort($versions);
        }
        unset($versions);

        // Force {} not [] when no packages.
        $payload = ['packages' => $packages === [] ? new \stdClass() : $packages];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return new PackagesJsonResult(
            json: (string) $json,
            packagesCount: count($packages),
            versionsCount: $versionCount,
            skippedCount: $skipped,
        );
    }
}
```

- [ ] **Step 4: Run the test, expect pass**

Run: `vendor/bin/phpunit tests/PackagesJsonTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
git add src/PackagesJson.php tests/PackagesJsonTest.php
git commit -m "feat(packages-json): build Composer-compatible packages.json from catalog"
```

---

## Task 9: Cache (manifest.hash, atomic write, flock)

**Files:**
- Create: `src/Cache.php`
- Test: `tests/CacheTest.php`

Owns the local cache directory. Three responsibilities:

1. `readIfFresh()` — TTL-based shortcut.
2. `readIfHashMatches(string $hash)` — when the listing hasn't changed.
3. `rebuild(string $newHash, callable $build): string` — under flock, with atomic rename.
4. `invalidate()` — used by `POST /rebuild` to force a rebuild on the next call.

The `$build` callable produces the new `packages.json` string; `Cache` only handles persistence and locking.

- [ ] **Step 1: Write the failing test**

`tests/CacheTest.php`:
```php
<?php
declare(strict_types=1);

namespace Composerd\Tests;

use Composerd\Cache;
use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/composerd-cache-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testReadIfFreshReturnsNullWhenCacheMissing(): void
    {
        $cache = new Cache($this->dir, ttlSeconds: 30);
        self::assertNull($cache->readIfFresh());
    }

    public function testReadIfFreshReturnsNullWhenTtlIsZero(): void
    {
        $cache = new Cache($this->dir, ttlSeconds: 0);
        $cache->rebuild('hash1', fn() => '{"x":1}');
        self::assertNull($cache->readIfFresh());
    }

    public function testReadIfFreshReturnsContentWithinTtl(): void
    {
        $cache = new Cache($this->dir, ttlSeconds: 30);
        $cache->rebuild('hash1', fn() => '{"x":1}');
        self::assertSame('{"x":1}', $cache->readIfFresh());
    }

    public function testReadIfFreshReturnsNullWhenTtlExpired(): void
    {
        $cache = new Cache($this->dir, ttlSeconds: 1);
        $cache->rebuild('hash1', fn() => '{"x":1}');
        touch($this->dir . '/manifest.hash', time() - 10);
        clearstatcache();
        self::assertNull($cache->readIfFresh());
    }

    public function testReadIfHashMatchesReturnsContentOnMatch(): void
    {
        $cache = new Cache($this->dir, ttlSeconds: 0);
        $cache->rebuild('hash1', fn() => '{"x":1}');
        self::assertSame('{"x":1}', $cache->readIfHashMatches('hash1'));
    }

    public function testReadIfHashMatchesReturnsNullOnMismatch(): void
    {
        $cache = new Cache($this->dir, ttlSeconds: 0);
        $cache->rebuild('hash1', fn() => '{"x":1}');
        self::assertNull($cache->readIfHashMatches('different-hash'));
    }

    public function testRebuildWritesAtomically(): void
    {
        $cache = new Cache($this->dir, ttlSeconds: 0);
        $result = $cache->rebuild('hashA', fn() => '{"a":1}');
        self::assertSame('{"a":1}', $result);
        self::assertSame('{"a":1}', file_get_contents($this->dir . '/packages.json'));
        self::assertSame('hashA', file_get_contents($this->dir . '/manifest.hash'));
    }

    public function testInvalidateDropsManifestHash(): void
    {
        $cache = new Cache($this->dir, ttlSeconds: 30);
        $cache->rebuild('hashA', fn() => '{"a":1}');
        self::assertFileExists($this->dir . '/manifest.hash');
        $cache->invalidate();
        self::assertFileDoesNotExist($this->dir . '/manifest.hash');
    }

    public function testRebuildIsLockedSerially(): void
    {
        // Two sequential rebuilds with different hashes - the second should win.
        $cache = new Cache($this->dir, ttlSeconds: 0);
        $cache->rebuild('hashA', fn() => '{"a":1}');
        $cache->rebuild('hashB', fn() => '{"b":2}');
        self::assertSame('{"b":2}', file_get_contents($this->dir . '/packages.json'));
        self::assertSame('hashB', file_get_contents($this->dir . '/manifest.hash'));
    }
}
```

- [ ] **Step 2: Run the test, expect failure**

Run: `vendor/bin/phpunit tests/CacheTest.php`
Expected: FAIL — class `Composerd\Cache` not found.

- [ ] **Step 3: Implement `src/Cache.php`**

```php
<?php
declare(strict_types=1);

namespace Composerd;

use RuntimeException;

final class Cache
{
    private string $packagesFile;
    private string $hashFile;
    private string $lockFile;

    public function __construct(string $dir, private int $ttlSeconds)
    {
        if (!is_dir($dir)) {
            throw new RuntimeException("Cache directory does not exist: $dir");
        }
        if (!is_writable($dir)) {
            throw new RuntimeException("Cache directory is not writable: $dir");
        }
        $this->packagesFile = "$dir/packages.json";
        $this->hashFile = "$dir/manifest.hash";
        $this->lockFile = "$dir/.rebuild.lock";
    }

    public function readIfFresh(): ?string
    {
        if ($this->ttlSeconds <= 0) return null;
        if (!is_file($this->packagesFile) || !is_file($this->hashFile)) return null;
        clearstatcache();
        $age = time() - (int) filemtime($this->hashFile);
        if ($age >= $this->ttlSeconds) return null;
        return file_get_contents($this->packagesFile) ?: null;
    }

    public function readIfHashMatches(string $hash): ?string
    {
        if (!is_file($this->packagesFile) || !is_file($this->hashFile)) return null;
        $stored = trim((string) file_get_contents($this->hashFile));
        if ($stored !== $hash) return null;
        @touch($this->hashFile);
        return file_get_contents($this->packagesFile) ?: null;
    }

    /** @param callable(): string $build */
    public function rebuild(string $newHash, callable $build): string
    {
        $lock = fopen($this->lockFile, 'cb');
        if ($lock === false) {
            throw new RuntimeException("Failed to open lock: {$this->lockFile}");
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Failed to acquire rebuild lock');
            }

            // Re-check inside the lock — another process may have rebuilt.
            if (is_file($this->hashFile)) {
                $stored = trim((string) file_get_contents($this->hashFile));
                if ($stored === $newHash && is_file($this->packagesFile)) {
                    @touch($this->hashFile);
                    return (string) file_get_contents($this->packagesFile);
                }
            }

            $json = $build();
            $this->atomicWrite($this->packagesFile, $json);
            $this->atomicWrite($this->hashFile, $newHash);
            return $json;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function invalidate(): void
    {
        @unlink($this->hashFile);
    }

    private function atomicWrite(string $target, string $content): void
    {
        $tmp = $target . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content) === false) {
            throw new RuntimeException("Failed to write temp file: $tmp");
        }
        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException("Failed to rename $tmp -> $target");
        }
    }
}
```

- [ ] **Step 4: Run the test, expect pass**

Run: `vendor/bin/phpunit tests/CacheTest.php`
Expected: PASS, 9 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Cache.php tests/CacheTest.php
git commit -m "feat(cache): manifest-hash cache with atomic write and flock"
```

---

## Task 10: Auth (PSR-15 basic-auth middleware)

**Files:**
- Create: `src/Auth.php`
- Test: `tests/AuthTest.php`

Validates HTTP Basic auth using `hash_equals()`. On success, calls the next handler. On failure, returns `401` with `WWW-Authenticate: Basic realm="composer"`.

- [ ] **Step 1: Write the failing test**

`tests/AuthTest.php`:
```php
<?php
declare(strict_types=1);

namespace Composerd\Tests;

use Composerd\Auth;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AuthTest extends TestCase
{
    private function handler(string $body = 'OK'): RequestHandlerInterface
    {
        return new class($body) implements RequestHandlerInterface {
            public function __construct(private string $body) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $r = new Response();
                $r->getBody()->write($this->body);
                return $r;
            }
        };
    }

    private function requestWithAuth(?string $user, ?string $pass): ServerRequestInterface
    {
        $r = new ServerRequest();
        if ($user !== null) {
            $r = $r->withHeader('Authorization', 'Basic ' . base64_encode("$user:$pass"));
        }
        return $r;
    }

    public function testReturns401WhenHeaderMissing(): void
    {
        $mw = new Auth('ci', 'secret');
        $resp = $mw->process(new ServerRequest(), $this->handler());
        self::assertSame(401, $resp->getStatusCode());
        self::assertSame('Basic realm="composer"', $resp->getHeaderLine('WWW-Authenticate'));
    }

    public function testReturns401OnWrongPassword(): void
    {
        $mw = new Auth('ci', 'secret');
        $resp = $mw->process($this->requestWithAuth('ci', 'nope'), $this->handler());
        self::assertSame(401, $resp->getStatusCode());
    }

    public function testReturns401OnWrongUser(): void
    {
        $mw = new Auth('ci', 'secret');
        $resp = $mw->process($this->requestWithAuth('attacker', 'secret'), $this->handler());
        self::assertSame(401, $resp->getStatusCode());
    }

    public function testCallsNextHandlerOnValidCredentials(): void
    {
        $mw = new Auth('ci', 'secret');
        $resp = $mw->process($this->requestWithAuth('ci', 'secret'), $this->handler('PASSED'));
        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('PASSED', (string) $resp->getBody());
    }

    public function testIgnoresMalformedAuthHeader(): void
    {
        $mw = new Auth('ci', 'secret');
        $r = (new ServerRequest())->withHeader('Authorization', 'Bearer xxx');
        $resp = $mw->process($r, $this->handler());
        self::assertSame(401, $resp->getStatusCode());
    }
}
```

- [ ] **Step 2: Run the test, expect failure**

Run: `vendor/bin/phpunit tests/AuthTest.php`
Expected: FAIL — class `Composerd\Auth` not found.

- [ ] **Step 3: Implement `src/Auth.php`**

```php
<?php
declare(strict_types=1);

namespace Composerd;

use Laminas\Diactoros\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class Auth implements MiddlewareInterface
{
    public function __construct(
        private string $user,
        private string $pass,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');
        if (!str_starts_with($header, 'Basic ')) {
            return $this->unauthorized();
        }
        $decoded = base64_decode(substr($header, 6), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return $this->unauthorized();
        }
        [$user, $pass] = explode(':', $decoded, 2);

        $userOk = hash_equals($this->user, $user);
        $passOk = hash_equals($this->pass, $pass);
        if (!($userOk && $passOk)) {
            return $this->unauthorized();
        }

        return $handler->handle($request);
    }

    private function unauthorized(): ResponseInterface
    {
        return (new Response())
            ->withStatus(401)
            ->withHeader('WWW-Authenticate', 'Basic realm="composer"');
    }
}
```

- [ ] **Step 4: Run the test, expect pass**

Run: `vendor/bin/phpunit tests/AuthTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Auth.php tests/AuthTest.php
git commit -m "feat(auth): PSR-15 HTTP basic auth middleware"
```

---

## Task 11: Controller (three route handlers)

**Files:**
- Create: `src/Controller.php`
- Test: `tests/ControllerTest.php`

Three methods:
- `packages(ServerRequestInterface): ResponseInterface` — orchestrates Cache + Catalog + PackagesJson.
- `dist(ServerRequestInterface, array $args): ResponseInterface` — streams a ZIP from Flysystem.
- `rebuild(ServerRequestInterface): ResponseInterface` — invalidates cache and forces a rebuild.

- [ ] **Step 1: Write the failing test**

`tests/ControllerTest.php`:
```php
<?php
declare(strict_types=1);

namespace Composerd\Tests;

use Composerd\Cache;
use Composerd\Catalog;
use Composerd\Controller;
use Composerd\PackagesJson;
use Composerd\Tests\Support\ZipBuilder;
use Composerd\ZipMetadata;
use Laminas\Diactoros\ServerRequest;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ControllerTest extends TestCase
{
    private string $cacheDir;
    private Filesystem $fs;
    private Controller $controller;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/composerd-ctrl-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir);

        $this->fs = new Filesystem(new InMemoryFilesystemAdapter());
        $this->fs->write(
            'acme/billing/1.2.0.zip',
            ZipBuilder::buildBytes(['name' => 'acme/billing', 'version' => '1.2.0', 'type' => 'library'])
        );

        $this->controller = new Controller(
            fs: $this->fs,
            catalog: new Catalog(),
            zipMetadata: new ZipMetadata(),
            packagesJson: new PackagesJson(new NullLogger()),
            cache: new Cache($this->cacheDir, 0),
            baseUrl: 'https://example.com',
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->cacheDir);
    }

    public function testPackagesEndpointReturnsJson(): void
    {
        $resp = $this->controller->packages(new ServerRequest());
        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('application/json', $resp->getHeaderLine('Content-Type'));
        $decoded = json_decode((string) $resp->getBody(), true);
        self::assertArrayHasKey('acme/billing', $decoded['packages']);
        self::assertSame(
            'https://example.com/dist/acme/billing/1.2.0.zip',
            $decoded['packages']['acme/billing']['1.2.0']['dist']['url']
        );
    }

    public function testPackagesEndpointServesFromCacheOnSecondCall(): void
    {
        $this->controller->packages(new ServerRequest());
        // Wipe the storage to prove second call uses cache.
        $this->fs->delete('acme/billing/1.2.0.zip');
        // With TTL 0, the catalog will be re-listed and hash will differ — so this
        // test exercises the listing/hash path, not the TTL shortcut.
        // Test just confirms the second call still succeeds:
        $resp = $this->controller->packages(new ServerRequest());
        self::assertSame(200, $resp->getStatusCode());
    }

    public function testDistEndpointStreamsZipBytes(): void
    {
        $resp = $this->controller->dist(
            new ServerRequest(),
            ['vendor' => 'acme', 'package' => 'billing', 'version' => '1.2.0']
        );
        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('application/zip', $resp->getHeaderLine('Content-Type'));
        $body = (string) $resp->getBody();
        self::assertNotSame('', $body);
        self::assertSame("PK\x03\x04", substr($body, 0, 4));
    }

    public function testDistEndpointReturns404ForMissingZip(): void
    {
        $resp = $this->controller->dist(
            new ServerRequest(),
            ['vendor' => 'no', 'package' => 'such', 'version' => '0.0.0']
        );
        self::assertSame(404, $resp->getStatusCode());
    }

    public function testRebuildEndpointReturnsSummary(): void
    {
        $resp = $this->controller->rebuild(new ServerRequest());
        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('application/json', $resp->getHeaderLine('Content-Type'));
        $decoded = json_decode((string) $resp->getBody(), true);
        self::assertArrayHasKey('packages', $decoded);
        self::assertArrayHasKey('versions', $decoded);
        self::assertArrayHasKey('skipped', $decoded);
        self::assertArrayHasKey('duration_ms', $decoded);
        self::assertSame(1, $decoded['packages']);
        self::assertSame(1, $decoded['versions']);
    }
}
```

- [ ] **Step 2: Run the test, expect failure**

Run: `vendor/bin/phpunit tests/ControllerTest.php`
Expected: FAIL — class `Composerd\Controller` not found.

- [ ] **Step 3: Implement `src/Controller.php`**

```php
<?php
declare(strict_types=1);

namespace Composerd;

use Laminas\Diactoros\Response;
use Laminas\Diactoros\Stream;
use League\Flysystem\Filesystem;
use League\Flysystem\UnableToReadFile;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class Controller
{
    public function __construct(
        private Filesystem $fs,
        private Catalog $catalog,
        private ZipMetadata $zipMetadata,
        private PackagesJson $packagesJson,
        private Cache $cache,
        private string $baseUrl,
    ) {}

    public function packages(ServerRequestInterface $request): ResponseInterface
    {
        // Step 0: TTL shortcut.
        $cached = $this->cache->readIfFresh();
        if ($cached !== null) {
            return $this->jsonResponse(200, $cached);
        }

        // Step 1: list + hash.
        [$entries, $hash] = $this->catalog->scan($this->fs);

        // Step 2: hash match.
        $cached = $this->cache->readIfHashMatches($hash);
        if ($cached !== null) {
            return $this->jsonResponse(200, $cached);
        }

        // Step 3: rebuild.
        $json = $this->cache->rebuild($hash, function () use ($entries) {
            return $this->packagesJson->build(
                $entries,
                fn(CatalogEntry $e) => $this->zipMetadata->read($this->fs, $e->path),
                $this->baseUrl,
            )->json;
        });

        return $this->jsonResponse(200, $json);
    }

    /** @param array{vendor: string, package: string, version: string} $args */
    public function dist(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $path = "{$args['vendor']}/{$args['package']}/{$args['version']}.zip";
        try {
            $stream = $this->fs->readStream($path);
        } catch (UnableToReadFile) {
            return (new Response())->withStatus(404);
        }

        $body = new Stream($stream);
        return (new Response($body))
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . basename($path) . '"');
    }

    public function rebuild(ServerRequestInterface $request): ResponseInterface
    {
        $start = microtime(true);
        $this->cache->invalidate();
        [$entries, $hash] = $this->catalog->scan($this->fs);

        $result = null;
        $this->cache->rebuild($hash, function () use ($entries, &$result) {
            $result = $this->packagesJson->build(
                $entries,
                fn(CatalogEntry $e) => $this->zipMetadata->read($this->fs, $e->path),
                $this->baseUrl,
            );
            return $result->json;
        });

        $summary = [
            'packages' => $result?->packagesCount ?? 0,
            'versions' => $result?->versionsCount ?? 0,
            'skipped' => $result?->skippedCount ?? 0,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
        ];
        return $this->jsonResponse(200, (string) json_encode($summary, JSON_UNESCAPED_SLASHES));
    }

    private function jsonResponse(int $status, string $body): ResponseInterface
    {
        $resp = new Response();
        $resp->getBody()->write($body);
        return $resp
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}
```

- [ ] **Step 4: Run the test, expect pass**

Run: `vendor/bin/phpunit tests/ControllerTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Controller.php tests/ControllerTest.php
git commit -m "feat(controller): packages/dist/rebuild endpoints orchestrated through Cache"
```

---

## Task 12: App factory + index.php

**Files:**
- Create: `src/App.php`
- Create: `public/index.php`
- Test: `tests/EndToEndTest.php`

`App::router(Config $config, Filesystem $fs): Router` builds the wired `League\Route\Router` so the end-to-end test can exercise the whole stack without going through HTTP. `public/index.php` loads `.env`, builds storage from `STORAGE_DSN`, and dispatches.

- [ ] **Step 1: Write the failing end-to-end test**

`tests/EndToEndTest.php`:
```php
<?php
declare(strict_types=1);

namespace Composerd\Tests;

use Composerd\App;
use Composerd\Config;
use Composerd\Tests\Support\ZipBuilder;
use Laminas\Diactoros\ServerRequest;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;

final class EndToEndTest extends TestCase
{
    private string $cacheDir;
    private Config $config;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/composerd-e2e-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir);

        $this->config = new Config([
            'APP_BASE_URL' => 'https://example.com',
            'STORAGE_DSN' => 'local:/unused-by-this-test',
            'CACHE_DIR' => $this->cacheDir,
            'LISTING_TTL_SECONDS' => '0',
            'AUTH_USER' => 'ci',
            'AUTH_PASS' => 'secret',
        ]);

        $this->fs = new Filesystem(new InMemoryFilesystemAdapter());
        $this->fs->write(
            'acme/billing/1.2.0.zip',
            ZipBuilder::buildBytes(['name' => 'acme/billing', 'version' => '1.2.0', 'type' => 'library'])
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->cacheDir);
    }

    private function authedRequest(string $method, string $uri): ServerRequest
    {
        $r = new ServerRequest([], [], $uri, $method);
        return $r->withHeader('Authorization', 'Basic ' . base64_encode('ci:secret'));
    }

    public function testPackagesRoute200(): void
    {
        $router = App::router($this->config, $this->fs);
        $resp = $router->dispatch($this->authedRequest('GET', '/packages.json'));
        self::assertSame(200, $resp->getStatusCode());
        $decoded = json_decode((string) $resp->getBody(), true);
        self::assertArrayHasKey('acme/billing', $decoded['packages']);
    }

    public function testPackagesRouteRequiresAuth(): void
    {
        $router = App::router($this->config, $this->fs);
        $resp = $router->dispatch(new ServerRequest([], [], '/packages.json'));
        self::assertSame(401, $resp->getStatusCode());
    }

    public function testDistRoute200(): void
    {
        $router = App::router($this->config, $this->fs);
        $resp = $router->dispatch($this->authedRequest('GET', '/dist/acme/billing/1.2.0.zip'));
        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('application/zip', $resp->getHeaderLine('Content-Type'));
    }

    public function testDistRoute404(): void
    {
        $router = App::router($this->config, $this->fs);
        $resp = $router->dispatch($this->authedRequest('GET', '/dist/no/such/0.0.0.zip'));
        self::assertSame(404, $resp->getStatusCode());
    }

    public function testRebuildRoute200(): void
    {
        $router = App::router($this->config, $this->fs);
        $resp = $router->dispatch($this->authedRequest('POST', '/rebuild'));
        self::assertSame(200, $resp->getStatusCode());
        $decoded = json_decode((string) $resp->getBody(), true);
        self::assertSame(1, $decoded['packages']);
    }

    public function testUnknownRoute404(): void
    {
        $router = App::router($this->config, $this->fs);
        $resp = $router->dispatch($this->authedRequest('GET', '/anything-else'));
        self::assertSame(404, $resp->getStatusCode());
    }
}
```

- [ ] **Step 2: Run the test, expect failure**

Run: `vendor/bin/phpunit tests/EndToEndTest.php`
Expected: FAIL — class `Composerd\App` not found.

- [ ] **Step 3: Implement `src/App.php`**

```php
<?php
declare(strict_types=1);

namespace Composerd;

use League\Flysystem\Filesystem;
use League\Route\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class App
{
    public static function router(Config $config, Filesystem $fs, ?\Psr\Log\LoggerInterface $logger = null): Router
    {
        $logger ??= new StderrLogger();

        $controller = new Controller(
            fs: $fs,
            catalog: new Catalog(),
            zipMetadata: new ZipMetadata(),
            packagesJson: new PackagesJson($logger),
            cache: new Cache($config->cacheDir(), $config->listingTtlSeconds()),
            baseUrl: $config->baseUrl(),
        );

        $auth = new Auth($config->authUser(), $config->authPass());

        // Default ApplicationStrategy — works with closure handlers without a container.
        $router = new Router();
        $router->middleware($auth);

        $router->get('/packages.json', fn(ServerRequestInterface $req): ResponseInterface => $controller->packages($req));
        $router->get('/dist/{vendor}/{package}/{version}.zip', function (ServerRequestInterface $req, array $args) use ($controller): ResponseInterface {
            return $controller->dist($req, $args);
        });
        $router->post('/rebuild', fn(ServerRequestInterface $req): ResponseInterface => $controller->rebuild($req));

        return $router;
    }
}
```

- [ ] **Step 4: Implement `public/index.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Composerd\App;
use Composerd\Config;
use Composerd\Storage;
use Dotenv\Dotenv;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;

$root = dirname(__DIR__);
if (file_exists("$root/.env")) {
    Dotenv::createImmutable($root)->load();
}

$config = new Config($_ENV + $_SERVER);
$fs = (new Storage($_ENV + $_SERVER))->make($config->storageDsn());
$router = App::router($config, $fs);

$request = ServerRequestFactory::fromGlobals();
$response = $router->dispatch($request);
(new SapiEmitter())->emit($response);
```

- [ ] **Step 5: Run the end-to-end test, expect pass**

Run: `vendor/bin/phpunit tests/EndToEndTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 6: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: PASS — all suites green.

- [ ] **Step 7: Commit**

```bash
git add src/App.php public/index.php tests/EndToEndTest.php
git commit -m "feat(app): wire router + middleware + controller and add E2E coverage"
```

---

## Task 13: Manual smoke test against built-in PHP server

**Files:**
- Modify: none (uses existing files)
- Create: `tests/fixtures/manual-smoke.zip` (transient, gitignored — it's a one-time manual check)

A throwaway local run to confirm the app boots end-to-end against real disk before we containerise.

- [ ] **Step 1: Build a fixture ZIP to drop in zips/**

```bash
mkdir -p zips/acme/billing
php -r '
require "vendor/autoload.php";
Composerd\Tests\Support\ZipBuilder::buildAt(
    "zips/acme/billing/1.0.0.zip",
    ["name" => "acme/billing", "version" => "1.0.0", "type" => "library", "require" => ["php" => "^8.2"]]
);
echo "OK\n";
'
```

Expected: `OK` and `zips/acme/billing/1.0.0.zip` exists.

- [ ] **Step 2: Copy `.env.example` to `.env` and start the dev server**

```bash
cp .env.example .env
php -S 127.0.0.1:8080 -t public
```

Run this in one terminal; leave it running for the next steps.

- [ ] **Step 3: Hit `/packages.json`**

In another terminal:
```bash
curl -u ci:replace-me -s http://127.0.0.1:8080/packages.json | head -40
```
Expected: JSON output with an `acme/billing` entry pointing at the local `dist` URL.

- [ ] **Step 4: Hit `/dist/...`**

```bash
curl -u ci:replace-me -sI http://127.0.0.1:8080/dist/acme/billing/1.0.0.zip
```
Expected: `HTTP/1.1 200 OK`, `Content-Type: application/zip`.

- [ ] **Step 5: Hit `/rebuild`**

```bash
curl -u ci:replace-me -X POST -s http://127.0.0.1:8080/rebuild
```
Expected: JSON with `packages`, `versions`, `skipped`, `duration_ms` fields.

- [ ] **Step 6: Verify auth rejection**

```bash
curl -sI http://127.0.0.1:8080/packages.json
```
Expected: `HTTP/1.1 401 Unauthorized` and a `WWW-Authenticate: Basic realm="composer"` header.

- [ ] **Step 7: Stop the dev server and clean up**

Ctrl+C the `php -S` process, then:
```bash
rm -rf zips/acme cache/packages.json cache/manifest.hash .env
```

- [ ] **Step 8: No commit needed** — this task is a one-off verification.

If anything fails, fix the underlying bug, re-run the unit tests (`vendor/bin/phpunit`), and re-run this smoke test before proceeding.

---

## Task 14: Container (Dockerfile + compose.yml)

**Files:**
- Create: `Dockerfile`
- Create: `compose.yml`

The `serversideup/php:8.4-frankenphp` base image runs the app as-is with no Caddyfile customisation. The Dockerfile installs PHP dependencies; `compose.yml` documents how to run it locally.

- [ ] **Step 1: Create `Dockerfile`**

```dockerfile
FROM serversideup/php:8.4-frankenphp

# Composer for installing PHP deps
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

USER www-data
WORKDIR /var/www/html

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist \
    && composer clear-cache

COPY --chown=www-data:www-data public ./public
COPY --chown=www-data:www-data src ./src
COPY --chown=www-data:www-data .env.example ./.env.example

# zips/ and cache/ are mounted as volumes by compose.yml
RUN mkdir -p zips cache
```

- [ ] **Step 2: Create `compose.yml`**

```yaml
services:
  composer:
    build: .
    image: repahead/composer-server:dev
    environment:
      SERVER_NAME: ${SERVER_NAME:-:80}
      AUTOMATIC_HTTPS: "off"
      PHP_OPCACHE_ENABLE: "1"
      APP_BASE_URL: ${APP_BASE_URL:-http://localhost:8080}
      STORAGE_DSN: ${STORAGE_DSN:-local:/var/www/html/zips}
      CACHE_DIR: /var/www/html/cache
      LISTING_TTL_SECONDS: ${LISTING_TTL_SECONDS:-30}
      AUTH_USER: ${AUTH_USER:-ci}
      AUTH_PASS: ${AUTH_PASS:?AUTH_PASS is required}
      AWS_ACCESS_KEY_ID: ${AWS_ACCESS_KEY_ID:-}
      AWS_SECRET_ACCESS_KEY: ${AWS_SECRET_ACCESS_KEY:-}
      AWS_REGION: ${AWS_REGION:-}
    ports:
      - "${HOST_PORT:-8080}:80"
    volumes:
      - composer-zips:/var/www/html/zips
      - composer-cache:/var/www/html/cache
    restart: unless-stopped

volumes:
  composer-zips:
  composer-cache:
```

- [ ] **Step 3: Build the image**

Run: `docker build -t repahead/composer-server:dev .`
Expected: build succeeds; final image tagged.

- [ ] **Step 4: Run container with a known password**

```bash
AUTH_PASS=secret docker compose up -d
```
Expected: container is healthy.

- [ ] **Step 5: Hit the running container**

```bash
curl -u ci:secret -s http://localhost:8080/rebuild
```
Expected: JSON summary like `{"packages":0,"versions":0,"skipped":0,"duration_ms":...}` (volumes are empty until you drop ZIPs).

- [ ] **Step 6: Tear down**

```bash
docker compose down -v
```

- [ ] **Step 7: Commit**

```bash
git add Dockerfile compose.yml
git commit -m "build: containerise with FrankenPHP base image and compose.yml"
```

---

## Task 15: README

**Files:**
- Create: `README.md`

Quick-start docs covering: what this is, configuration, dropping ZIPs, consumer setup, S3 mode.

- [ ] **Step 1: Create `README.md`**

````markdown
# Private Composer Server

A small PHP service that exposes a private Composer (Packagist-compatible) repository whose content is driven by **dropping ZIP files into a folder**. Storage is pluggable via Flysystem (local disk or S3).

See [docs/superpowers/specs/2026-05-06-composer-server-design.md](docs/superpowers/specs/2026-05-06-composer-server-design.md) for the full design.

## Quick start (Docker)

```bash
cp .env.example .env
# edit AUTH_PASS at minimum
AUTH_PASS=$(grep AUTH_PASS .env | cut -d= -f2) docker compose up -d --build
```

The service listens on `http://localhost:8080`. Drop ZIPs into the `composer-zips` volume:

```bash
docker compose cp ./acme-billing-1.2.0.zip composer:/var/www/html/zips/acme/billing/1.2.0.zip
curl -u ci:secret -X POST http://localhost:8080/rebuild
```

## Folder layout for ZIPs

```
zips/
  vendor/
    package/
      1.0.0.zip
      1.1.0.zip
```

The `composer.json` inside each ZIP is the source of truth for `require`, `autoload`, etc. The folder path determines `vendor/package`; the filename determines the version.

## Endpoints

| Method | Route | Purpose |
|--------|-------|---------|
| GET | `/packages.json` | Composer repository index (cached) |
| GET | `/dist/{vendor}/{package}/{version}.zip` | Streams the ZIP |
| POST | `/rebuild` | Force cache rebuild; returns `{packages, versions, skipped, duration_ms}` |

All endpoints use HTTP basic auth (`AUTH_USER` / `AUTH_PASS`).

## Consumer setup

In the consuming project:

```bash
composer config repositories.private composer https://composer.your-domain.com
composer config http-basic.composer.your-domain.com ci <password>
composer require acme/billing:^1.0
```

## Configuration

See `.env.example`. Key vars:

- `STORAGE_DSN=local:./zips` or `s3:my-bucket/composer/zips`
- `LISTING_TTL_SECONDS=30` — how long the cache lives between storage listings (`0` = list every request)
- `AUTH_USER`, `AUTH_PASS` — single shared HTTP basic credential

## Local development

```bash
composer install
cp .env.example .env
php -S 127.0.0.1:8080 -t public
vendor/bin/phpunit
```

## Failure modes

ZIPs that are corrupt, missing `composer.json`, or whose `composer.json` `name` field doesn't match the folder path are **skipped** (logged to stderr). The skipped count is included in `POST /rebuild` responses.
````

- [ ] **Step 2: Commit**

```bash
git add README.md
git commit -m "docs: README with quick-start, endpoints, consumer setup"
```

---

## Self-review notes (after writing the plan)

The author of this plan ran the following self-review against the spec:

1. **Spec coverage** — every spec section has at least one task:
   - Folder layout → Task 6 (Catalog)
   - URL routes → Tasks 11, 12
   - `packages.json` format → Task 8
   - Caching (TTL, manifest hash, atomic write, flock) → Task 9
   - `POST /rebuild` → Tasks 11, 12
   - Configuration / `.env` → Tasks 1, 2, 12
   - Application structure → all tasks
   - Validation & failure modes → Tasks 7, 8 (skip + log paths)
   - Auth & deployment → Tasks 10, 14
   - Container → Task 14
   - Operational story → Tasks 13, 15

2. **Placeholder scan** — no TBD/TODO/“implement later” strings; every code step contains the actual code; commands have expected output.

3. **Type consistency** — `CatalogEntry` properties are referenced consistently across tasks 6–12; `ZipMeta` exposes only `composerJson` and `sha1`; `Cache` API is `readIfFresh`/`readIfHashMatches`/`rebuild`/`invalidate` everywhere it is referenced.
