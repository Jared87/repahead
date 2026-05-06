<?php

declare(strict_types=1);

namespace RepAhead\Tests\Catalog;

use RepAhead\Catalog\ZipMetadata;
use RepAhead\Tests\Support\ZipBuilder;
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
