<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Diff;

/**
 * Result of comparing one group of system files (configs, redirects, other
 * files) of the RESOURCE layout, reported as a section of its own.
 */
final class SystemSection
{
    /**
     * @param list<ResourceDiff> $diffs
     */
    public function __construct(
        public readonly string $name,
        public readonly array $diffs,
        public readonly int $totalA,
        public readonly int $totalB,
        public readonly int $identical,
    ) {}
}
