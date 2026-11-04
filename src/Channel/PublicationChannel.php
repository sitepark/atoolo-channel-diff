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

    /**
     * Directories next to the resource tree that hold system files, as
     * key prefix => absolute path. Only the RESOURCE layout has them; in
     * DOCUMENT_ROOT they are part of the resource tree already.
     *
     * @return array<string, string>
     */
    public function systemDirs(): array
    {
        if ($this->layout !== ChannelLayout::RESOURCE) {
            return [];
        }

        return [
            'configs' => $this->baseDir . '/configs',
            'redirects' => $this->baseDir . '/redirects',
        ];
    }

    /**
     * Key prefix of the resource tree below the base directory ("objects").
     */
    public function resourcePrefix(): string
    {
        $base = rtrim($this->baseDir, '/') . '/';

        return str_starts_with($this->resourceDir, $base)
            ? substr($this->resourceDir, strlen($base))
            : 'objects';
    }
}
