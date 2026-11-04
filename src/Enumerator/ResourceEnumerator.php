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
     * PHP files of one system directory next to the resource tree ("configs",
     * "redirects"). Keys carry the directory name as prefix.
     *
     * @return array<string, string> key => absolute path
     */
    public function systemResources(
        PublicationChannel $channel,
        string $name,
        ChannelScope $scope = new ChannelScope(),
    ): array {
        $root = $channel->systemDirs()[$name] ?? null;
        if ($root === null) {
            return [];
        }

        return $this->collectScoped($channel, $root, $scope, true, $name);
    }

    /**
     * Non-PHP files of the resource tree and of the system directories
     * (htaccess, htpasswd, JSON, ...). Keys carry the directory name as
     * prefix; editor leftovers (swap and backup files) are skipped. Empty in
     * the DOCUMENT_ROOT layout, where these files are indistinguishable from
     * media.
     *
     * @return array<string, string> key => absolute path
     */
    public function files(
        PublicationChannel $channel,
        ChannelScope $scope = new ChannelScope(),
    ): array {
        $dirs = $channel->systemDirs();
        if ($dirs === []) {
            return [];
        }

        $map = $this->collectScoped(
            $channel,
            $channel->resourceDir,
            $scope,
            false,
            $channel->resourcePrefix(),
            true,
        );
        foreach ($dirs as $name => $root) {
            $map += $this->collectScoped($channel, $root, $scope, false, $name, true);
        }
        ksort($map);

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function collectScoped(
        PublicationChannel $channel,
        string $root,
        ChannelScope $scope,
        bool $php,
        string $rootKey = '',
        bool $skipEditorFiles = false,
    ): array {
        $prefix = $scope->prefixWithin($channel->baseDir, $root);
        if ($prefix === null) {
            // The scope lies outside this tree entirely.
            return [];
        }

        return $this->collect($root, $channel->layout, $php, $prefix, $rootKey, $skipEditorFiles);
    }

    /**
     * @param string $prefix sub directory of $root to walk; '' means the whole
     *                       root. Keys stay relative to $root either way.
     * @param string $rootKey prepended to every key; '' for the resource and
     *                        media trees, the directory name for system files
     * @return array<string, string>
     */
    private function collect(
        string $root,
        ChannelLayout $layout,
        bool $php,
        string $prefix,
        string $rootKey = '',
        bool $skipEditorFiles = false,
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

        if ($skipEditorFiles) {
            // Regex rather than globs: a glob star does not match a leading dot,
            // and vim swap files are dot files.
            $finder->notName('/\.sw[a-p]$|~$/');
        }

        if ($php) {
            $finder->name('*.php');
        } else {
            $finder->notName('*.php');
        }

        $keyPrefix = ($rootKey === '' ? '' : $rootKey . '/')
            . ($prefix === '' ? '' : $prefix . '/');

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
