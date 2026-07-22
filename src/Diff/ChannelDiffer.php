<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Diff;

use Atoolo\ChannelDiff\Channel\PublicationChannel;
use Atoolo\ChannelDiff\Enumerator\ResourceEnumerator;
use Atoolo\ChannelDiff\Loader\ResourceFileReader;
use Atoolo\ChannelDiff\Loader\ResourceReadException;

/**
 * Orchestrates the comparison of two publication channels: matches resources
 * and media by relative path, diffs resource arrays and hashes binary media.
 */
final class ChannelDiffer
{
    public function __construct(
        private readonly ResourceEnumerator $enumerator,
        private readonly ResourceFileReader $reader,
        private readonly ArrayDiffer $arrayDiffer,
    ) {}

    public function diff(
        PublicationChannel $a,
        PublicationChannel $b,
        IgnoreList $ignore,
        bool $includeMedia = true,
        bool $nullEqualsMissing = true,
        bool $emptyStringEqualsMissing = true,
        bool $emptyArrayEqualsMissing = true,
        bool $normalizeUuidKeys = true,
    ): DiffReport {
        [$resourceDiffs, $resourcesIdentical, $totalA, $totalB] = $this->diffResources(
            $a,
            $b,
            $ignore,
            $nullEqualsMissing,
            $emptyStringEqualsMissing,
            $emptyArrayEqualsMissing,
            $normalizeUuidKeys,
        );

        $mediaDiffs = [];
        $mediaIdentical = 0;
        $totalMediaA = 0;
        $totalMediaB = 0;
        if ($includeMedia) {
            [$mediaDiffs, $mediaIdentical, $totalMediaA, $totalMediaB]
                = $this->diffMedia($a, $b);
        }

        return new DiffReport(
            $a,
            $b,
            $resourceDiffs,
            $mediaDiffs,
            $totalA,
            $totalB,
            $resourcesIdentical,
            $totalMediaA,
            $totalMediaB,
            $mediaIdentical,
            $includeMedia,
        );
    }

    /**
     * @return array{0: list<ResourceDiff>, 1: int, 2: int, 3: int}
     */
    private function diffResources(
        PublicationChannel $a,
        PublicationChannel $b,
        IgnoreList $ignore,
        bool $nullEqualsMissing,
        bool $emptyStringEqualsMissing,
        bool $emptyArrayEqualsMissing,
        bool $normalizeUuidKeys,
    ): array {
        $mapA = $this->enumerator->resources($a);
        $mapB = $this->enumerator->resources($b);

        $diffs = [];
        $identical = 0;

        foreach ($this->unionKeys($mapA, $mapB) as $key) {
            $inA = isset($mapA[$key]);
            $inB = isset($mapB[$key]);

            if ($inA && !$inB) {
                $diffs[] = new ResourceDiff(
                    $key,
                    EntryStatus::ONLY_IN_A,
                    label: $this->safeLabel($mapA[$key]),
                );
                continue;
            }
            if (!$inA && $inB) {
                $diffs[] = new ResourceDiff(
                    $key,
                    EntryStatus::ONLY_IN_B,
                    label: $this->safeLabel($mapB[$key]),
                );
                continue;
            }

            try {
                $dataA = $this->reader->read($mapA[$key]);
                $dataB = $this->reader->read($mapB[$key]);
            } catch (ResourceReadException $e) {
                $diffs[] = new ResourceDiff(
                    $key,
                    EntryStatus::READ_ERROR,
                    error: $e->getMessage(),
                );
                continue;
            }

            $fieldDiffs = $this->arrayDiffer->diff(
                $dataA,
                $dataB,
                $ignore,
                $nullEqualsMissing,
                $emptyStringEqualsMissing,
                $emptyArrayEqualsMissing,
                $normalizeUuidKeys,
            );
            if ($fieldDiffs === []) {
                $identical++;
                continue;
            }

            $diffs[] = new ResourceDiff(
                $key,
                EntryStatus::CHANGED,
                fieldDiffs: $fieldDiffs,
            );
        }

        return [$diffs, $identical, count($mapA), count($mapB)];
    }

    /**
     * @return array{0: list<MediaDiff>, 1: int, 2: int, 3: int}
     */
    private function diffMedia(PublicationChannel $a, PublicationChannel $b): array
    {
        $mapA = $this->enumerator->media($a);
        $mapB = $this->enumerator->media($b);

        $diffs = [];
        $identical = 0;

        foreach ($this->unionKeys($mapA, $mapB) as $key) {
            $inA = isset($mapA[$key]);
            $inB = isset($mapB[$key]);

            if ($inA && !$inB) {
                $diffs[] = new MediaDiff($key, EntryStatus::ONLY_IN_A);
                continue;
            }
            if (!$inA && $inB) {
                $diffs[] = new MediaDiff($key, EntryStatus::ONLY_IN_B);
                continue;
            }

            $hashA = $this->hash($mapA[$key]);
            $hashB = $this->hash($mapB[$key]);
            if ($hashA === $hashB) {
                $identical++;
                continue;
            }

            $diffs[] = new MediaDiff($key, EntryStatus::CHANGED, $hashA, $hashB);
        }

        return [$diffs, $identical, count($mapA), count($mapB)];
    }

    /**
     * @param array<string, string> $a
     * @param array<string, string> $b
     * @return list<string>
     */
    private function unionKeys(array $a, array $b): array
    {
        $keys = array_keys($a + $b);
        sort($keys);
        return $keys;
    }

    private function hash(string $file): string
    {
        $hash = hash_file('sha256', $file);
        return $hash !== false ? $hash : '';
    }

    private function safeLabel(string $file): ?string
    {
        try {
            $data = $this->reader->read($file);
        } catch (ResourceReadException) {
            return null;
        }

        $parts = [];
        if (isset($data['id'])) {
            $parts[] = 'id=' . (is_scalar($data['id']) ? (string) $data['id'] : '?');
        }
        if (isset($data['objectType']) && is_scalar($data['objectType'])) {
            $parts[] = (string) $data['objectType'];
        }
        if (isset($data['name']) && is_scalar($data['name'])) {
            $parts[] = '"' . (string) $data['name'] . '"';
        }

        return $parts === [] ? null : implode(' ', $parts);
    }
}
