<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Diff;

/**
 * Difference of a single resource (matched by relative path) between channels.
 */
final class ResourceDiff
{
    /**
     * @param list<FieldDiff> $fieldDiffs
     * @param string|null $textDiff unified-like line diff of a text file, or
     *        a note for a binary one; set instead of $fieldDiffs
     */
    public function __construct(
        public readonly string $key,
        public readonly EntryStatus $status,
        public readonly array $fieldDiffs = [],
        public readonly ?string $label = null,
        public readonly ?string $error = null,
        public readonly ?string $textDiff = null,
    ) {}
}
