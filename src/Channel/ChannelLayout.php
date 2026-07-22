<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Channel;

/**
 * Physical layout of a publication channel on disk.
 *
 * The distinction between URL-based and ID-based RESOURCE layouts is
 * irrelevant for a physical-path based diff and therefore not modelled here;
 * it is captured informally via PublicationChannel::$resourcePathType.
 */
enum ChannelLayout: string
{
    /** Everything in one directory (resources and media intermixed). */
    case DOCUMENT_ROOT = 'document_root';

    /** Separate objects/ and media/public/ directories below the base dir. */
    case RESOURCE = 'resource';
}
