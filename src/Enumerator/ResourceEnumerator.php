<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Enumerator;

use Atoolo\ChannelDiff\Channel\ChannelLayout;
use Atoolo\ChannelDiff\Channel\PublicationChannel;
use Symfony\Component\Finder\Finder;

/**
 * Walks a publication channel's filesystem and returns maps of
 * relative path => absolute path, used as the matching key for the diff.
 */
final class ResourceEnumerator
{
    /**
     * Resource PHP files (content resources, media meta, translations).
     *
     * @return array<string, string> relative path => absolute path
     */
    public function resources(PublicationChannel $channel): array
    {
        return $this->collect($channel->resourceDir, $channel->layout, true);
    }

    /**
     * Binary media files (everything that is not a PHP file).
     *
     * @return array<string, string> relative path => absolute path
     */
    public function media(PublicationChannel $channel): array
    {
        return $this->collect($channel->mediaDir, $channel->layout, false);
    }

    /**
     * @return array<string, string>
     */
    private function collect(string $root, ChannelLayout $layout, bool $php): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $finder = (new Finder())
            ->files()
            ->in($root)
            ->ignoreDotFiles(false)
            ->notName('*.tmp')
            ->notName('sp_*');

        // In DOCUMENT_ROOT the SiteKit framework (WEB-IES) lives inside the
        // resource tree; it is infrastructure, not publication content.
        if ($layout === ChannelLayout::DOCUMENT_ROOT) {
            $finder->notPath('WEB-IES');
        }

        if ($php) {
            $finder->name('*.php');
        } else {
            $finder->notName('*.php');
        }

        $map = [];
        foreach ($finder as $file) {
            $relative = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                $file->getRelativePathname(),
            );
            $map[$relative] = $file->getRealPath() ?: $file->getPathname();
        }

        ksort($map);

        return $map;
    }
}
