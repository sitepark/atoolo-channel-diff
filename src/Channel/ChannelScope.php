<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Channel;

use InvalidArgumentException;

/**
 * Restricts a comparison to a sub directory of a publication channel.
 *
 * The sub path is always relative to the channel's base directory, so a single
 * path expression addresses both the resource tree and the media tree, even
 * though those live in different sub directories in the RESOURCE layout.
 */
final class ChannelScope
{
    public function __construct(
        public readonly string $subPath = '',
    ) {}

    /**
     * Normalizes user input into a scope: backslashes become slashes, empty and
     * "." segments are dropped, surrounding slashes are trimmed.
     *
     * @throws InvalidArgumentException if the path tries to escape the channel
     */
    public static function fromInput(?string $subPath): self
    {
        if ($subPath === null) {
            return new self();
        }

        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $subPath)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new InvalidArgumentException(sprintf(
                    'Sub path "%s" must not contain ".." segments.',
                    $subPath,
                ));
            }
            $segments[] = $segment;
        }

        return new self(implode('/', $segments));
    }

    /**
     * True if the scope covers the whole channel.
     */
    public function isAll(): bool
    {
        return $this->subPath === '';
    }

    /**
     * Absolute location of the scope inside the given channel base directory.
     */
    public function absolutePath(string $baseDir): string
    {
        $baseDir = rtrim($baseDir, '/');

        return $this->isAll() ? $baseDir : $baseDir . '/' . $this->subPath;
    }

    /**
     * The part of the scope that lies inside $root, as a path relative to
     * $root. An empty string means "the whole root"; null means the scope and
     * $root are disjoint, so $root contributes nothing to the comparison.
     */
    public function prefixWithin(string $baseDir, string $root): ?string
    {
        $target = $this->absolutePath($baseDir);
        $root = rtrim($root, '/');

        if ($target === $root) {
            return '';
        }
        if (str_starts_with($target, $root . '/')) {
            return substr($target, strlen($root) + 1);
        }
        // The scope is an ancestor of the root: the root is fully included.
        if (str_starts_with($root, $target . '/')) {
            return '';
        }

        return null;
    }
}
