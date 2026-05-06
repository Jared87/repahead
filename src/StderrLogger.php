<?php
declare(strict_types=1);

namespace Composerd;

use Psr\Log\AbstractLogger;
use Stringable;

final class StderrLogger extends AbstractLogger
{
    /** @param array<string,mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $line = sprintf(
            "[%s] %s %s%s\n",
            date('c'),
            strtoupper((string) $level),
            (string) $message,
            $context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : ''
        );
        fwrite(STDERR, $line);
    }
}
