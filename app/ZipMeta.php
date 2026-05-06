<?php

declare(strict_types=1);

namespace RepAhead;

final readonly class ZipMeta
{
    /** @param array<string,mixed> $composerJson */
    public function __construct(
        public array $composerJson,
        public string $sha1,
    ) {
    }
}
