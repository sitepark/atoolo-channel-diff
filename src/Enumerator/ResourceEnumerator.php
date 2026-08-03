<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Enumerator;

use Atoolo\ChannelDiff\Channel\ChannelLayout;
use Atoolo\ChannelDiff\Channel\ChannelScope;
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
    public function resources(
        PublicationChannel $channel,
        ChannelScope $scope = new ChannelScope(),
    ): array {
        return $this->collectScoped(
            $channel,
            $channel->resourceDir,
            $scope,
            true,
        );
    }

    /**
     * Binary media files (everything that is not a PHP file).
     *
     * @return array<string, string> relative path => absolute path
     */
    public function media(
        PublicationChannel $channel,
        ChannelScope $scope = new ChannelScope(),
    ): array {
        return $this->collectScoped(
            $channel,
            $channel->mediaDir,
            $scope,
            false,
        );
    }

    /**
     * @return array<string, string>
     */
    private function collectScoped(
        PublicationChannel $channel,
        string $root,
        ChannelScope $scope,
        bool $php,
    ): array {
        $prefix = $scope->prefixWithin($channel->baseDir, $root);
        if ($prefix === null) {
            // The scope lies outside this tree entirely.
            return [];
        }

        return $this->collect($root, $channel->layout, $php, $prefix);
    }

    /**
     * @param string $prefix sub directory of $root to walk; '' means the whole
     *                       root. Keys stay relative to $root either way.
     * @return array<string, string>
     */
    private function collect(
        string $root,
        ChannelLayout $layout,
        bool $php,
        string $prefix,
    ): array {
        // In DOCUMENT_ROOT the SiteKit framework (WEB-IES) lives inside the
        // resource tree; it is infrastructure, not publication content. The
        // Finder filter below only sees paths below $prefix, so a prefix that
        // already points into WEB-IES has to be rejected up front.
        $excludeWebIes = $layout === ChannelLayout::DOCUMENT_ROOT;
        if ($excludeWebIes && str_contains($prefix, 'WEB-IES')) {
            return [];
        }

        $dir = $prefix === '' ? $root : $root . '/' . $prefix;
        if (!is_dir($dir)) {
            return [];
        }

        $finder = (new Finder())
            ->files()
            ->in($dir)
            ->ignoreDotFiles(false)
            ->notName('*.tmp')
            ->notName('sp_*');

        if ($excludeWebIes) {
            $finder->notPath('WEB-IES');
        }

        if ($php) {
            $finder->name('*.php');
        } else {
            $finder->notName('*.php');
        }

        $keyPrefix = $prefix === '' ? '' : $prefix . '/';

        $map = [];
        foreach ($finder as $file) {
            $relative = $keyPrefix . str_replace(
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
