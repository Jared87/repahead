<?php

declare(strict_types=1);

namespace Composerd;

final readonly class ZipMeta
{
    /** @param array<string,mixed> $composerJson */
    public function __construct(
        public array $composerJson,
        public string $sha1,
    ) {
    }
}
