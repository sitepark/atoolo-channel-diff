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
    ) {}

    public function hasDifferences(): bool
    {
        return $this->resourceDiffs !== [] || $this->mediaDiffs !== [];
    }
}
