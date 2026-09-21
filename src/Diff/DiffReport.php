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
    ) {}

    public function hasDifferences(): bool
    {
        return $this->resourceDiffs !== [] || $this->mediaDiffs !== [];
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
