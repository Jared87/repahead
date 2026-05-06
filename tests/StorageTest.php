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
