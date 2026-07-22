<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Diff;

/**
 * Kind of change of a single field between channel A and channel B.
 */
enum ChangeType: string
{
    /** Present only in channel B. */
    case ADDED = 'added';

    /** Present only in channel A. */
    case REMOVED = 'removed';

    /** Present in both but with a different value. */
    case CHANGED = 'changed';
}
