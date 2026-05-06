<?php

declare(strict_types=1);

namespace Composerd;

use RuntimeException;

final readonly class Cache
{
    private string $packagesFile;
    private string $hashFile;
    private string $lockFile;

    public function __construct(string $dir, private int $ttlSeconds)
    {
        if (!is_dir($dir)) {
            throw new RuntimeException("Cache directory does not exist: $dir");
        }
        if (!is_writable($dir)) {
            throw new RuntimeException("Cache directory is not writable: $dir");
        }
        $this->packagesFile = "$dir/packages.json";
        $this->hashFile = "$dir/manifest.hash";
        $this->lockFile = "$dir/.rebuild.lock";
    }

    public function readIfFresh(): ?string
    {
        if ($this->ttlSeconds <= 0) {
            return null;
        }
        if (!is_file($this->packagesFile) || !is_file($this->hashFile)) {
            return null;
        }
        clearstatcache();
        $age = time() - (int) filemtime($this->hashFile);
        if ($age >= $this->ttlSeconds) {
            return null;
        }
        return file_get_contents($this->packagesFile) ?: null;
    }

    public function readIfHashMatches(string $hash): ?string
    {
        if (!is_file($this->packagesFile) || !is_file($this->hashFile)) {
            return null;
        }
        $stored = trim((string) file_get_contents($this->hashFile));
        if ($stored !== $hash) {
            return null;
        }
        @touch($this->hashFile);
        return file_get_contents($this->packagesFile) ?: null;
    }

    /** @param callable(): string $build */
    public function rebuild(string $newHash, callable $build): string
    {
        $lock = fopen($this->lockFile, 'cb');
        if ($lock === false) {
            throw new RuntimeException("Failed to open lock: {$this->lockFile}");
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Failed to acquire rebuild lock');
            }

            // Re-check inside the lock — another process may have rebuilt.
            if (is_file($this->hashFile)) {
                $stored = trim((string) file_get_contents($this->hashFile));
                if ($stored === $newHash && is_file($this->packagesFile)) {
                    @touch($this->hashFile);
                    return (string) file_get_contents($this->packagesFile);
                }
            }

            $json = $build();
            $this->atomicWrite($this->packagesFile, $json);
            $this->atomicWrite($this->hashFile, $newHash);
            return $json;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function invalidate(): void
    {
        @unlink($this->hashFile);
    }

    private function atomicWrite(string $target, string $content): void
    {
        $tmp = $target . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content) === false) {
            throw new RuntimeException("Failed to write temp file: $tmp");
        }
        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException("Failed to rename $tmp -> $target");
        }
    }
}
