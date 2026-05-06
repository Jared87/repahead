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
