<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Diff;

/**
 * Difference of a single media file (matched by relative path).
 *
 * A changed media file that is text on both sides - robots.txt, a user-defined
 * page, a CSS include published as a medium - carries the line diff as well;
 * a binary one only its hashes.
 */
final class MediaDiff
{
    public function __construct(
        public readonly string $key,
        public readonly EntryStatus $status,
        public readonly ?string $hashA = null,
        public readonly ?string $hashB = null,
        public readonly ?string $textDiff = null,
    ) {}
}
