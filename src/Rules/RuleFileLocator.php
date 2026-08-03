<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Rules;

/**
 * Finds the rule file that applies to a channel by walking up from the channel
 * base directory. This lets one file next to two channels (e.g. in ".../www"
 * for ".../www/resources" and ".../www/resources.old") govern both.
 */
final class RuleFileLocator
{
    /**
     * Accepted file names, in order of preference.
     */
    public const FILE_NAMES = [
        'channel-diff.yaml',
        'channel-diff.yml',
    ];

    /**
     * The nearest rule file at or above $startDir, or null if there is none.
     */
    public function locate(string $startDir): ?string
    {
        $dir = realpath($startDir);
        if ($dir === false) {
            return null;
        }

        while (true) {
            foreach (self::FILE_NAMES as $name) {
                $candidate = $dir . '/' . $name;
                if (is_file($candidate)) {
                    return $candidate;
                }
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                return null;
            }
            $dir = $parent;
        }
    }

    /**
     * Locates the rule files for several start directories, deduplicated: two
     * channels below a common directory usually resolve to the same file.
     *
     * @param list<string> $startDirs
     * @return list<string>
     */
    public function locateAll(array $startDirs): array
    {
        $files = [];
        foreach ($startDirs as $startDir) {
            $file = $this->locate($startDir);
            if ($file !== null) {
                $files[$file] = true;
            }
        }

        return array_keys($files);
    }
}
