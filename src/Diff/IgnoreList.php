<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Diff;

/**
 * Set of dot-notation field paths that are excluded from the array diff.
 *
 * A path is ignored when it exactly matches an entry or lies below one
 * (e.g. entry "searchindexdata" ignores "searchindexdata.content" as well).
 *
 * Wildcards in a pattern:
 *  - `*` matches exactly one key segment;
 *  - `**` matches any number of segments (including none), so a structure that
 *    occurs at many depths can be addressed once, e.g. "**.geo.features.*".
 *
 * A `*` in the middle of a path is a wildcard ignore, e.g. "**.geo.features.*.id"
 * ignores the "id" field of every child of any "geo.features" array. A terminal
 * `*`, e.g. "**.geo.features.*", is a key-normalization marker: the matched
 * array is compared by the content of its values, not by their (volatile) keys.
 * Normalization is handled by {@see ArrayDiffer}, not skipped as an ignore.
 */
final class IgnoreList
{
    /**
     * Volatile fields that differ between two publications of the same content
     * without representing a meaningful content change.
     */
    public const DEFAULT_PATHS = [
        'version',
        'created',
        'changed',
        'generated',
        'changedBy',
        'cacheInfo',
    ];

    /**
     * @var list<string>
     */
    private readonly array $paths;

    /**
     * Segments of ignore patterns (last segment is not `*`).
     *
     * @var list<list<string>>
     */
    private readonly array $ignoreSegments;

    /**
     * Segments of key-normalization bases (pattern without its terminal `.*`).
     *
     * @var list<list<string>>
     */
    private readonly array $normalizeSegments;

    /**
     * @param iterable<string> $paths
     */
    public function __construct(iterable $paths)
    {
        $normalized = [];
        foreach ($paths as $path) {
            $path = trim($path);
            if ($path !== '') {
                $normalized[$path] = true;
            }
        }
        $this->paths = array_keys($normalized);

        $ignore = [];
        $normalize = [];
        foreach ($this->paths as $path) {
            $segments = explode('.', $path);
            if (end($segments) === '*') {
                array_pop($segments);
                $normalize[] = $segments;
            } else {
                $ignore[] = $segments;
            }
        }
        $this->ignoreSegments = $ignore;
        $this->normalizeSegments = $normalize;
    }

    public static function default(): self
    {
        return new self(self::DEFAULT_PATHS);
    }

    /**
     * @param iterable<string> $paths
     */
    public function withAdditional(iterable $paths): self
    {
        return new self([...$this->paths, ...$paths]);
    }

    public function isIgnored(string $path): bool
    {
        $pathSegments = explode('.', $path);
        foreach ($this->ignoreSegments as $pattern) {
            // prefixMode: the pattern may match a prefix of the path so that a
            // whole subtree below a matched field is ignored as well.
            if ($this->globMatch($pattern, $pathSegments, 0, 0, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the array at the given path should be compared by the content of
     * its values instead of by their keys (terminal `*` marker).
     */
    public function isKeyNormalized(string $prefix): bool
    {
        $prefixSegments = $prefix === '' ? [] : explode('.', $prefix);
        foreach ($this->normalizeSegments as $base) {
            if ($this->globMatch($base, $prefixSegments, 0, 0, false)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return $this->paths;
    }

    /**
     * Glob-matches $pattern segments against $path segments, where `*` matches
     * one segment and `**` matches any number of segments. When $prefixMode is
     * true, leftover path segments after the pattern is exhausted still count
     * as a match (subtree matching).
     *
     * @param list<string> $pattern
     * @param list<string> $path
     */
    private function globMatch(array $pattern, array $path, int $pi, int $si, bool $prefixMode): bool
    {
        $patternCount = count($pattern);
        $pathCount = count($path);

        while ($pi < $patternCount) {
            $segment = $pattern[$pi];

            if ($segment === '**') {
                if ($pi === $patternCount - 1) {
                    return true;
                }
                for ($k = $si; $k <= $pathCount; $k++) {
                    if ($this->globMatch($pattern, $path, $pi + 1, $k, $prefixMode)) {
                        return true;
                    }
                }
                return false;
            }

            if ($si >= $pathCount) {
                return false;
            }
            if ($segment !== '*' && $segment !== $path[$si]) {
                return false;
            }
            $pi++;
            $si++;
        }

        return $prefixMode || $si === $pathCount;
    }
}
