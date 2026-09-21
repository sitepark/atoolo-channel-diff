<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Diff;

/**
 * How much one resource-exclusion pattern actually took out of a comparison.
 *
 * Counted and reported per pattern on purpose: a rule that no longer matches
 * anything hides nothing today, but still hides the next difference that
 * appears on that path. Seeing `(no match)` in the report is what makes such
 * a rule removable.
 */
final class ExclusionStat
{
    public function __construct(
        public readonly string $pattern,
        public readonly int $resources,
        public readonly int $media,
    ) {}

    public function isUnused(): bool
    {
        return $this->resources === 0 && $this->media === 0;
    }
}
