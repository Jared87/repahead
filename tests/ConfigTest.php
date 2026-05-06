<?php

declare(strict_types=1);

namespace RepAhead\Tests;

use RepAhead\Config;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    /** @return array<string,string> */
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
