<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Diff;

/**
 * Internal carrier for the settings that steer a single array comparison,
 * so they need not be threaded through every recursive call individually.
 */
final class DiffContext
{
    public function __construct(
        public readonly IgnoreList $ignore,
        public readonly bool $nullEqualsMissing,
        public readonly bool $emptyStringEqualsMissing,
        public readonly bool $emptyArrayEqualsMissing,
        public readonly bool $normalizeUuidKeys = true,
        public readonly ?float $floatTolerance = null,
        public readonly bool $numericStringsEqualNumbers = false,
    ) {}
}
