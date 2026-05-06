<?php
declare(strict_types=1);

namespace Composerd\Tests\Support;

use League\Flysystem\DirectoryListing;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToReadFile;

/**
 * Test double that lets specific Filesystem operations be configured to throw.
 *
 * Default behaviour delegates to a real InMemoryFilesystemAdapter, so all the
 * other methods (write, read, delete) work as in normal tests.
 */
final class ThrowingFilesystem extends Filesystem
{
    private bool $listThrows = false;
    private bool $readStreamThrows = false;

    public function __construct()
    {
        parent::__construct(new InMemoryFilesystemAdapter());
    }

    public function failListContents(): void
    {
        $this->listThrows = true;
    }

    public function failReadStream(): void
    {
        $this->readStreamThrows = true;
    }

    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
    {
        if ($this->listThrows) {
            throw UnableToListContents::atLocation($location, $deep, new \RuntimeException('induced failure'));
        }
        return parent::listContents($location, $deep);
    }

    public function readStream(string $location)
    {
        if ($this->readStreamThrows) {
            throw UnableToReadFile::fromLocation($location, 'induced failure');
        }
        return parent::readStream($location);
    }
}
