<?php

declare(strict_types=1);

namespace RepAhead\Tests;

use RepAhead\Storage;
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
        $tmp = sys_get_temp_dir() . '/repahead-storage-' . bin2hex(random_bytes(4));
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

    public function testS3DsnWithAmbientCredentialsReturnsFilesystem(): void
    {
        // No key/secret — SDK provider chain resolves credentials at request time.
        // Region must still be present (env or explicit); Lambda/ECS set AWS_REGION automatically.
        $fs = (new Storage(['AWS_REGION' => 'eu-central-1']))->make('s3:bucket/prefix');
        self::assertInstanceOf(Filesystem::class, $fs);
    }

    public function testS3DsnWithExplicitCredentialsReturnsFilesystem(): void
    {
        $fs = (new Storage([
            'AWS_ACCESS_KEY_ID' => 'key',
            'AWS_SECRET_ACCESS_KEY' => 'secret',
            'AWS_REGION' => 'eu-central-1',
        ]))->make('s3:bucket/prefix');
        self::assertInstanceOf(Filesystem::class, $fs);
    }

    public function testS3DsnWithPartialCredentialsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/AWS_ACCESS_KEY_ID.*AWS_SECRET_ACCESS_KEY|AWS_SECRET_ACCESS_KEY.*AWS_ACCESS_KEY_ID/');
        (new Storage(['AWS_ACCESS_KEY_ID' => 'key']))->make('s3:bucket/prefix');
    }
}
