<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Channel;

/**
 * Resolved view of one IES publication channel: the directories that hold
 * resource PHP files and binary media, plus the detected layout.
 */
final class PublicationChannel
{
    public function __construct(
        public readonly string $baseDir,
        public readonly string $resourceDir,
        public readonly string $mediaDir,
        public readonly ChannelLayout $layout,
        public readonly string $locale,
        public readonly string $resourcePathType,
        public readonly string $name,
    ) {}
}
