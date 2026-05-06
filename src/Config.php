<?php
declare(strict_types=1);

namespace Composerd;

use InvalidArgumentException;

final readonly class Config
{
    private const REQUIRED = [
        'APP_BASE_URL',
        'STORAGE_DSN',
        'CACHE_DIR',
        'AUTH_USER',
        'AUTH_PASS',
    ];

    private string $baseUrl;
    private string $storageDsn;
    private string $cacheDir;
    private int $listingTtl;
    private string $authUser;
    private string $authPass;

    /** @param array<string,string> $env */
    public function __construct(array $env)
    {
        foreach (self::REQUIRED as $key) {
            if (!isset($env[$key]) || $env[$key] === '') {
                throw new InvalidArgumentException("Missing required env var: $key");
            }
        }

        $this->baseUrl = rtrim($env['APP_BASE_URL'], '/');
        $this->storageDsn = $env['STORAGE_DSN'];
        $this->cacheDir = $env['CACHE_DIR'];
        $this->authUser = $env['AUTH_USER'];
        $this->authPass = $env['AUTH_PASS'];

        $ttlRaw = $env['LISTING_TTL_SECONDS'] ?? '0';
        if (!ctype_digit($ttlRaw)) {
            throw new InvalidArgumentException(
                "LISTING_TTL_SECONDS must be a non-negative integer, got: $ttlRaw"
            );
        }
        $this->listingTtl = (int) $ttlRaw;
    }

    public function baseUrl(): string { return $this->baseUrl; }
    public function storageDsn(): string { return $this->storageDsn; }
    public function cacheDir(): string { return $this->cacheDir; }
    public function listingTtlSeconds(): int { return $this->listingTtl; }
    public function authUser(): string { return $this->authUser; }
    public function authPass(): string { return $this->authPass; }
}
