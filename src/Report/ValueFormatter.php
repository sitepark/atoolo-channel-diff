<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Report;

/**
 * Formats arbitrary resource values into compact, single-line strings for
 * human-readable output.
 */
final class ValueFormatter
{
    private const MAX_LENGTH = 160;

    public function format(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value instanceof \Closure) {
            return '<closure>';
        }
        if (is_scalar($value)) {
            return $this->truncate((string) $value);
        }

        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return $this->truncate($json !== false ? $json : '<unserializable>');
    }

    private function truncate(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        if (mb_strlen($value) <= self::MAX_LENGTH) {
            return $value;
        }
        return mb_substr($value, 0, self::MAX_LENGTH - 1) . '…';
    }
}
