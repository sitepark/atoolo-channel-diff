<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Diff;

/**
 * Difference of a single binary media file (matched by relative path).
 */
final class MediaDiff
{
    public function __construct(
        public readonly string $key,
        public readonly EntryStatus $status,
        public readonly ?string $hashA = null,
        public readonly ?string $hashB = null,
    ) {}
}
