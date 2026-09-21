<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Diff;

/**
 * Set of resource paths that are left out of the comparison altogether.
 *
 * Unlike {@see IgnoreList}, which drops single fields out of a resource that
 * is still compared, an entry here removes the whole resource from both
 * channels: it is neither counted as identical nor reported as a difference.
 * That is for the case where one channel cannot produce a page at all — a
 * legacy publisher that does not know a class the newer one does, for example
 * — so the page is one-sided by construction and no field comparison is
 * possible.
 *
 * Patterns are slash-separated paths, matched against the resource key the
 * report shows, which is the path relative to the channel's resource
 * directory:
 *  - `*` matches any characters within one path segment;
 *  - `?` matches one character within a segment;
 *  - a `**` segment matches any number of segments, including none, so a
 *    leading `**` in front of `index.php` covers `index.php` and
 *    `de/city/index.php` alike.
 *
 * A pattern matches a whole key, not a prefix: to exclude a directory, say
 * `testseiten/**` rather than `testseiten`. Since `**` stands for any number
 * of segments including none, that pattern also covers a file named exactly
 * `testseiten` - one rule without a special case for the trailing position.
 *
 * **Media follow their resource.** IES publishes the media of a resource into
 * a sidecar directory named after it, `<resource>.media/...`, in the media
 * tree. Excluding a resource therefore excludes those files too — otherwise
 * every rendition of an excluded page would still be reported as one-sided.
 * A pattern is additionally matched against media keys directly, so a purely
 * binary tree can be excluded the same way.
 */
final class ResourceExclusionList
{
    /**
     * Sidecar directory suffix under which IES publishes a resource's media.
     */
    private const MEDIA_SUFFIX = '.media';

    /**
     * @var list<string>
     */
    private readonly array $patterns;

    /**
     * Pattern segments, in the order of {@see $patterns}.
     *
     * @var list<list<string>>
     */
    private readonly array $segments;

    /**
     * @param iterable<string> $patterns
     */
    public function __construct(iterable $patterns = [])
    {
        $normalized = [];
        foreach ($patterns as $pattern) {
            $pattern = trim(str_replace('\\', '/', $pattern));
            $pattern = trim($pattern, '/');
            if ($pattern !== '') {
                $normalized[$pattern] = true;
            }
        }

        $this->patterns = array_keys($normalized);
        $this->segments = array_map(
            static fn(string $pattern): array => explode('/', $pattern),
            $this->patterns,
        );
    }

    public function isEmpty(): bool
    {
        return $this->patterns === [];
    }

    /**
     * @return list<string>
     */
    public function patterns(): array
    {
        return $this->patterns;
    }

    /**
     * The first pattern that excludes this resource, or null if none does.
     *
     * The first one wins so that a key is attributed to exactly one pattern,
     * which keeps the per-pattern counts in the report adding up.
     */
    public function matchResource(string $key): ?string
    {
        return $this->firstMatch($key);
    }

    /**
     * The first pattern that excludes this media file, or null if none does.
     *
     * A media file is excluded either because a pattern names it directly, or
     * because it lies in the sidecar directory of an excluded resource.
     */
    public function matchMedia(string $key): ?string
    {
        $direct = $this->firstMatch($key);
        if ($direct !== null) {
            return $direct;
        }

        $resource = self::sidecarResourceOf($key);

        return $resource === null ? null : $this->firstMatch($resource);
    }

    /**
     * The resource whose sidecar directory holds this media file, or null if
     * the path is not below such a directory.
     *
     * The first `.media` segment ends the resource path: `page.php.media/1/a.jpg`
     * belongs to `page.php`. Anything deeper is inside that one sidecar.
     */
    private static function sidecarResourceOf(string $key): ?string
    {
        $segments = explode('/', $key);
        $path = [];
        foreach ($segments as $segment) {
            if (str_ends_with($segment, self::MEDIA_SUFFIX)) {
                $base = substr($segment, 0, -strlen(self::MEDIA_SUFFIX));
                if ($base === '') {
                    return null;
                }
                $path[] = $base;
                return implode('/', $path);
            }
            $path[] = $segment;
        }

        return null;
    }

    private function firstMatch(string $key): ?string
    {
        $keySegments = explode('/', $key);
        foreach ($this->segments as $index => $pattern) {
            if (self::globMatch($pattern, $keySegments, 0, 0)) {
                return $this->patterns[$index];
            }
        }

        return null;
    }

    /**
     * Glob-matches $pattern segments against $key segments, where a `**`
     * segment matches any number of segments and every other segment is
     * matched against exactly one key segment.
     *
     * @param list<string> $pattern
     * @param list<string> $key
     */
    private static function globMatch(array $pattern, array $key, int $pi, int $si): bool
    {
        $patternCount = count($pattern);
        $keyCount = count($key);

        while ($pi < $patternCount) {
            $segment = $pattern[$pi];

            if ($segment === '**') {
                if ($pi === $patternCount - 1) {
                    return true;
                }
                for ($k = $si; $k <= $keyCount; $k++) {
                    if (self::globMatch($pattern, $key, $pi + 1, $k)) {
                        return true;
                    }
                }
                return false;
            }

            if ($si >= $keyCount) {
                return false;
            }
            if (!self::segmentMatch($segment, $key[$si])) {
                return false;
            }
            $pi++;
            $si++;
        }

        return $si === $keyCount;
    }

    /**
     * Matches one pattern segment against one key segment, honouring `*` and
     * `?` inside the segment.
     *
     * fnmatch() is deliberately not used: it is not available on every build,
     * and its flags differ between platforms.
     */
    private static function segmentMatch(string $pattern, string $segment): bool
    {
        if (!str_contains($pattern, '*') && !str_contains($pattern, '?')) {
            return $pattern === $segment;
        }

        $regex = '';
        foreach (str_split($pattern) as $char) {
            $regex .= match ($char) {
                '*' => '.*',
                '?' => '.',
                default => preg_quote($char, '#'),
            };
        }

        return preg_match('#^' . $regex . '$#u', $segment) === 1;
    }
}
