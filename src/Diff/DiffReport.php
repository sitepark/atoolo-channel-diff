<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Diff;

use Atoolo\ChannelDiff\Channel\ChannelScope;
use Atoolo\ChannelDiff\Channel\PublicationChannel;
use Atoolo\ChannelDiff\Rules\RuleSet;

/**
 * Aggregated result of comparing two publication channels.
 */
final class DiffReport
{
    /**
     * @param list<ResourceDiff> $resourceDiffs
     * @param list<MediaDiff> $mediaDiffs
     * @param int $totalResourcesA resources compared in A, excluded ones
     *        already taken out
     * @param int $resourcesExcluded resource keys left out by an exclusion
     *        rule, counted over both channels together
     * @param int $mediaExcluded media keys left out by an exclusion rule,
     *        counted over both channels together
     * @param list<ExclusionStat> $exclusionStats what each exclusion pattern
     *        actually removed
     * @param list<SystemSection> $systemSections configs, redirects and other
     *        files outside the resource and media trees; only sections that
     *        hold at least one file in either channel
     */
    public function __construct(
        public readonly PublicationChannel $channelA,
        public readonly PublicationChannel $channelB,
        public readonly array $resourceDiffs,
        public readonly array $mediaDiffs,
        public readonly int $totalResourcesA,
        public readonly int $totalResourcesB,
        public readonly int $resourcesIdentical,
        public readonly int $totalMediaA,
        public readonly int $totalMediaB,
        public readonly int $mediaIdentical,
        public readonly bool $mediaCompared,
        public readonly ChannelScope $scope = new ChannelScope(),
        public readonly RuleSet $rules = new RuleSet(),
        public readonly int $resourcesExcluded = 0,
        public readonly int $mediaExcluded = 0,
        public readonly array $exclusionStats = [],
        public readonly array $systemSections = [],
    ) {}

    public function hasDifferences(): bool
    {
        if ($this->resourceDiffs !== [] || $this->mediaDiffs !== []) {
            return true;
        }
        foreach ($this->systemSections as $section) {
            if ($section->diffs !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Exclusion patterns that removed nothing from this run — candidates for
     * deletion, and a warning that the rule may be hiding a future difference
     * for no current reason.
     *
     * @return list<ExclusionStat>
     */
    public function unusedExclusions(): array
    {
        return array_values(array_filter(
            $this->exclusionStats,
            static fn(ExclusionStat $stat): bool => $stat->isUnused(),
        ));
    }
}
