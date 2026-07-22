<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Diff;

/**
 * A single difference at a dot-notation field path within a resource array.
 */
final class FieldDiff
{
    public function __construct(
        public readonly string $path,
        public readonly ChangeType $type,
        public readonly mixed $oldValue,
        public readonly mixed $newValue,
    ) {}
}
