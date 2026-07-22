<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Channel;

use Atoolo\Resource\Factory\SiteKitResourceChannelFactory;
use InvalidArgumentException;

/**
 * Builds a {@see PublicationChannel} from a publication base directory by
 * reusing the resource bundle's channel factory, which auto-detects the
 * layout (DOCUMENT_ROOT vs. RESOURCE) via the location of context.php.
 */
final class ChannelFactory
{
    public function create(string $baseDir): PublicationChannel
    {
        $baseDir = rtrim($baseDir, '/');
        if ($baseDir === '' || !is_dir($baseDir)) {
            throw new InvalidArgumentException(
                sprintf('Channel base directory "%s" does not exist.', $baseDir),
            );
        }

        $channel = (new SiteKitResourceChannelFactory($baseDir))->create();

        $isDocumentRoot = $channel->resourceDir === $channel->baseDir;
        $layout = $isDocumentRoot
            ? ChannelLayout::DOCUMENT_ROOT
            : ChannelLayout::RESOURCE;

        // DOCUMENT_ROOT: media files are intermixed with the resource files.
        // RESOURCE: binary media live in a sibling media/public/ tree.
        $mediaDir = $isDocumentRoot
            ? $channel->baseDir
            : $channel->baseDir . '/media/public';

        return new PublicationChannel(
            $channel->baseDir,
            $channel->resourceDir,
            $mediaDir,
            $layout,
            $channel->locale,
            $channel->attributes->getString('resourcePathType'),
            $channel->name,
        );
    }
}
