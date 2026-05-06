<?php
declare(strict_types=1);

namespace Composerd\Tests\Support;

use ZipArchive;

final class ZipBuilder
{
    /**
     * Build a ZIP at the given path containing a composer.json plus optional extras.
     *
     * @param array<string,mixed> $composerJson
     * @param array<string,string> $extraFiles  filename => content
     */
    public static function buildAt(string $path, array $composerJson, array $extraFiles = []): void
    {
        $zip = new ZipArchive();
        $rc = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($rc !== true) {
            throw new \RuntimeException("Failed to open ZIP for writing: $path (code $rc)");
        }
        $zip->addFromString('composer.json', json_encode($composerJson, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        foreach ($extraFiles as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
    }

    /** Returns binary contents of a ZIP built in a temp path then read+deleted. */
    public static function buildBytes(array $composerJson, array $extraFiles = []): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'composerd-zip');
        if ($tmp === false) {
            throw new \RuntimeException('tempnam failed');
        }
        try {
            self::buildAt($tmp, $composerJson, $extraFiles);
            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /** Build a corrupt ZIP (just garbage bytes) for negative tests. */
    public static function buildCorruptBytes(): string
    {
        return "PK\x03\x04this-is-not-a-valid-zip-file";
    }
}
