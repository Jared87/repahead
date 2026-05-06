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
        $cache->rebuild('hash1', fn (): string => '{"x":1}');
        self::assertNull($cache->readIfFresh());
    }

    public function testReadIfFreshReturnsContentWithinTtl(): void
    {
        $cache = new Cache($this->dir, ttlSeconds: 30);
        $cache->rebuild('hash1', fn (): string => '{"x":1}');
        self::assertSame('{"x":1}', $cache->readIfFresh());
    }

    public function testReadIfFreshReturnsNullWhenTtlExpired(): void
    {
        $cache = new Cache($this->dir, ttlSeconds: 1);
        $cache->rebuild('hash1', fn (): string => '{"x":1}');
        touch($this->dir . '/manifest.hash', time() - 10);
        clearstatcache();
        self::assertNull($cache->readIfFresh());
    }

    public function testReadIfHashMatchesReturnsContentOnMatch(): void
    {
        $cache = new Cache($this->dir, ttlSeconds: 0);
        $cache->rebuild('hash1', fn (): string => '{"x":1}');
        self::assertSame('{"x":1}', $cache->readIfHashMatches('hash1'));
    }

    public function testReadIfHashMatchesReturnsNullOnMismatch(): void
    {
        $cache = new Cache($this->dir, ttlSeconds: 0);
        $cache->rebuild('hash1', fn (): string => '{"x":1}');
        self::assertNull($cache->readIfHashMatches('different-hash'));
    }

    public function testRebuildWritesAtomically(): void
    {
        $cache = new Cache($this->dir, ttlSeconds: 0);
        $result = $cache->rebuild('hashA', fn (): string => '{"a":1}');
        self::assertSame('{"a":1}', $result);
        self::assertSame('{"a":1}', file_get_contents($this->dir . '/packages.json'));
        self::assertSame('hashA', file_get_contents($this->dir . '/manifest.hash'));
    }

    public function testInvalidateDropsManifestHash(): void
    {
        $cache = new Cache($this->dir, ttlSeconds: 30);
        $cache->rebuild('hashA', fn (): string => '{"a":1}');
        self::assertFileExists($this->dir . '/manifest.hash');
        $cache->invalidate();
        self::assertFileDoesNotExist($this->dir . '/manifest.hash');
    }

    public function testRebuildIsLockedSerially(): void
    {
        // Two sequential rebuilds with different hashes - the second should win.
        $cache = new Cache($this->dir, ttlSeconds: 0);
        $cache->rebuild('hashA', fn (): string => '{"a":1}');
        $cache->rebuild('hashB', fn (): string => '{"b":2}');
        self::assertSame('{"b":2}', file_get_contents($this->dir . '/packages.json'));
        self::assertSame('hashB', file_get_contents($this->dir . '/manifest.hash'));
    }
}
