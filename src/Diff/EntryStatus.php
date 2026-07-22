<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Diff;

/**
 * Status of a single resource or media entry across both channels.
 */
enum EntryStatus: string
{
    case ONLY_IN_A = 'only_in_a';
    case ONLY_IN_B = 'only_in_b';
    case CHANGED = 'changed';
    case READ_ERROR = 'read_error';
}
