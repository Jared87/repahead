<?php
declare(strict_types=1);

namespace Composerd;

use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;

final class Catalog
{
    /**
     * @return array{0: list<CatalogEntry>, 1: string}  [entries, sha256-of-listing]
     */
    public function scan(Filesystem $fs): array
    {
        $entries = [];
        foreach ($fs->listContents('', true) as $item) {
            if (!$item instanceof FileAttributes) {
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
            fn(CatalogEntry $a, CatalogEntry $b): int => strcmp($a->path, $b->path)
        );

        $hashInput = '';
        foreach ($entries as $e) {
            $hashInput .= "$e->path|$e->size|$e->lastModified\n";
        }
        $hash = hash('sha256', $hashInput);

        return [$entries, $hash];
    }
}
