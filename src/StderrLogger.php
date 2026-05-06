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
        $message = $this->interpolate((string) $message, $context);
        $line = sprintf(
            "[%s] %s %s%s\n",
            date('c'),
            strtoupper((string) $level),
            $message,
            $context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : ''
        );
        fwrite(STDERR, $line);
    }

    /**
     * Replace `{key}` placeholders in the message with values from the context array.
     * Per the PSR-3 spec, only string-coercible scalars are interpolated.
     *
     * @param array<string,mixed> $context
     */
    private function interpolate(string $message, array $context): string
    {
        if ($context === [] || !str_contains($message, '{')) {
            return $message;
        }
        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null || $value instanceof Stringable) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }
        return $replacements === [] ? $message : strtr($message, $replacements);
    }
}
