<?php
declare(strict_types=1);

namespace Composerd;

use League\Flysystem\Filesystem;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use ZipArchive;

final readonly class ZipMetadata
{
    public function __construct(private LoggerInterface $logger = new NullLogger()) {}

    public function read(Filesystem $fs, string $path): ?ZipMeta
    {
        $tmp = tempnam(sys_get_temp_dir(), 'composerd-zip');
        if ($tmp === false) {
            $this->logger->warning('Skipping ZIP: tempnam failed', ['path' => $path]);
            return null;
        }
        try {
            try {
                $stream = $fs->readStream($path);
            } catch (Throwable $e) {
                $this->logger->warning('Skipping ZIP: stream open failed', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }

            $out = fopen($tmp, 'wb');
            if ($out === false) {
                $this->logger->warning('Skipping ZIP: temp open for write failed', ['path' => $path]);
                return null;
            }
            try {
                if (stream_copy_to_stream($stream, $out) === false) {
                    $this->logger->warning('Skipping ZIP: stream copy failed', ['path' => $path]);
                    return null;
                }
            } finally {
                fclose($out);
            }

            $sha1 = sha1_file($tmp);
            if ($sha1 === false) {
                $this->logger->warning('Skipping ZIP: sha1 computation failed', ['path' => $path]);
                return null;
            }

            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) {
                $this->logger->warning('Skipping ZIP: corrupt archive', ['path' => $path]);
                return null;
            }
            try {
                $raw = $zip->getFromName('composer.json');
                if ($raw === false) {
                    $this->logger->warning('Skipping ZIP: composer.json missing', ['path' => $path]);
                    return null;
                }
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    $this->logger->warning('Skipping ZIP: composer.json is not valid JSON object', ['path' => $path]);
                    return null;
                }
                return new ZipMeta($decoded, $sha1);
            } finally {
                $zip->close();
            }
        } catch (Throwable $e) {
            $this->logger->warning('Skipping ZIP: unexpected error', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        } finally {
            @unlink($tmp);
        }
    }
}
