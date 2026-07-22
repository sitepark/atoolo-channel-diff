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
     */
    public function __construct(
        public readonly string $key,
        public readonly EntryStatus $status,
        public readonly array $fieldDiffs = [],
        public readonly ?string $label = null,
        public readonly ?string $error = null,
    ) {}
}
