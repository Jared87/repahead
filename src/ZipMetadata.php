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
