<?php

declare(strict_types=1);

namespace RepAhead\Catalog;

final readonly class Release
{
    public function __construct(
        public string $vendor,
        public string $package,
        public string $version,
        public string $path,
        public int $size,
        public int $lastModified,
    ) {
    }

    public function fullName(): string
    {
        return "{$this->vendor}/{$this->package}";
    }
}
