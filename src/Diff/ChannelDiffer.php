<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Diff;

use Atoolo\ChannelDiff\Channel\ChannelScope;
use Atoolo\ChannelDiff\Channel\PublicationChannel;
use Atoolo\ChannelDiff\Enumerator\ResourceEnumerator;
use Atoolo\ChannelDiff\Loader\ResourceFileReader;
use Atoolo\ChannelDiff\Loader\ResourceReadException;
use Atoolo\ChannelDiff\Rules\RuleSet;
use Closure;

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
        private readonly FileDiffer $fileDiffer = new FileDiffer(),
    ) {}

    public function diff(
        PublicationChannel $a,
        PublicationChannel $b,
        IgnoreList $ignore,
        ChannelScope $scope = new ChannelScope(),
        RuleSet $rules = new RuleSet(),
        bool $includeMedia = true,
        bool $nullEqualsMissing = true,
        bool $emptyStringEqualsMissing = true,
        bool $emptyArrayEqualsMissing = true,
        bool $normalizeUuidKeys = true,
    ): DiffReport {
        // Applied here rather than by the caller, so that passing a rule set
        // always applies all of it.
        $ignore = $ignore->withAdditional($rules->excludes);
        $exclusions = new ResourceExclusionList($rules->excludeResources);

        $floatTolerance = $rules->floatTolerance();
        $compare = fn(array $dataA, array $dataB): array => $this->arrayDiffer->diff(
            $dataA,
            $dataB,
            $ignore,
            $nullEqualsMissing,
            $emptyStringEqualsMissing,
            $emptyArrayEqualsMissing,
            $normalizeUuidKeys,
            $floatTolerance,
            $rules->numericStringsEqualNumbers,
        );

        [
            $resourceDiffs,
            $resourcesIdentical,
            $totalA,
            $totalB,
            $resourceHits,
        ] = $this->diffResources(
            $this->enumerator->resources($a, $scope),
            $this->enumerator->resources($b, $scope),
            $exclusions,
            $compare,
        );

        [$systemSections, $systemHits] = $this->diffSystem(
            $a,
            $b,
            $scope,
            $exclusions,
            $compare,
            new ResourceExclusionList($rules->maskHashes),
        );
        foreach ($systemHits as $pattern => $count) {
            $resourceHits[$pattern] += $count;
        }

        $mediaDiffs = [];
        $mediaIdentical = 0;
        $totalMediaA = 0;
        $totalMediaB = 0;
        $mediaHits = self::noHits($exclusions);
        if ($includeMedia) {
            [$mediaDiffs, $mediaIdentical, $totalMediaA, $totalMediaB, $mediaHits]
                = $this->diffMedia($a, $b, $scope, $exclusions);
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
            $scope,
            $rules,
            array_sum($resourceHits),
            array_sum($mediaHits),
            self::exclusionStats($exclusions, $resourceHits, $mediaHits),
            $systemSections,
        );
    }

    /**
     * Configs, redirects and non-PHP files; each section appears only if
     * either channel holds a file for it, so channels without them report
     * exactly what they always did.
     *
     * @param Closure(array<array-key, mixed>, array<array-key, mixed>): list<FieldDiff> $compare
     * @return array{0: list<SystemSection>, 1: array<string, int>}
     */
    private function diffSystem(
        PublicationChannel $a,
        PublicationChannel $b,
        ChannelScope $scope,
        ResourceExclusionList $exclusions,
        Closure $compare,
        ResourceExclusionList $maskHashes,
    ): array {
        $sections = [];
        $hits = self::noHits($exclusions);

        foreach (['configs' => 'Configs', 'redirects' => 'Redirects'] as $dir => $title) {
            $mapA = $this->enumerator->systemResources($a, $dir, $scope);
            $mapB = $this->enumerator->systemResources($b, $dir, $scope);
            if ($mapA === [] && $mapB === []) {
                continue;
            }
            [$diffs, $identical, $totalA, $totalB, $sectionHits]
                = $this->diffResources($mapA, $mapB, $exclusions, $compare);
            foreach ($sectionHits as $pattern => $count) {
                $hits[$pattern] += $count;
            }
            $sections[] = new SystemSection($title, $diffs, $totalA, $totalB, $identical);
        }

        $mapA = $this->enumerator->files($a, $scope);
        $mapB = $this->enumerator->files($b, $scope);
        if ($mapA !== [] || $mapB !== []) {
            [$diffs, $identical, $totalA, $totalB, $sectionHits]
                = $this->diffFiles($mapA, $mapB, $exclusions, $compare, $maskHashes);
            foreach ($sectionHits as $pattern => $count) {
                $hits[$pattern] += $count;
            }
            $sections[] = new SystemSection('Files', $diffs, $totalA, $totalB, $identical);
        }

        return [$sections, $hits];
    }

    /**
     * @param array<string, string> $mapA
     * @param array<string, string> $mapB
     * @param Closure(array<array-key, mixed>, array<array-key, mixed>): list<FieldDiff> $compare
     * @return array{0: list<ResourceDiff>, 1: int, 2: int, 3: int, 4: array<string, int>}
     */
    private function diffFiles(
        array $mapA,
        array $mapB,
        ResourceExclusionList $exclusions,
        Closure $compare,
        ResourceExclusionList $maskHashes,
    ): array {
        $diffs = [];
        $identical = 0;
        $hits = self::noHits($exclusions);

        foreach ($this->unionKeys($mapA, $mapB) as $key) {
            $pattern = $exclusions->matchResource($key);
            if ($pattern !== null) {
                $hits[$pattern]++;
                unset($mapA[$key], $mapB[$key]);
                continue;
            }

            $inA = isset($mapA[$key]);
            $inB = isset($mapB[$key]);
            if ($inA !== $inB) {
                $diffs[] = new ResourceDiff(
                    $key,
                    $inA ? EntryStatus::ONLY_IN_A : EntryStatus::ONLY_IN_B,
                );
                continue;
            }

            $diff = $this->fileDiffer->compare(
                $key,
                $mapA[$key],
                $mapB[$key],
                $compare,
                $maskHashes->matchResource($key) !== null,
            );
            if ($diff === null) {
                $identical++;
                continue;
            }
            $diffs[] = $diff;
        }

        return [$diffs, $identical, count($mapA), count($mapB), $hits];
    }

    /**
     * @param array<string, string> $mapA
     * @param array<string, string> $mapB
     * @param Closure(array<array-key, mixed>, array<array-key, mixed>): list<FieldDiff> $compare
     * @return array{0: list<ResourceDiff>, 1: int, 2: int, 3: int, 4: array<string, int>}
     */
    private function diffResources(
        array $mapA,
        array $mapB,
        ResourceExclusionList $exclusions,
        Closure $compare,
    ): array {
        $diffs = [];
        $identical = 0;
        $hits = self::noHits($exclusions);

        foreach ($this->unionKeys($mapA, $mapB) as $key) {
            $pattern = $exclusions->matchResource($key);
            if ($pattern !== null) {
                // Counted once for the key, however many channels hold it:
                // the excluded unit is the resource, not the file.
                $hits[$pattern]++;
                unset($mapA[$key], $mapB[$key]);
                continue;
            }

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

            $fieldDiffs = $compare($dataA, $dataB);
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

        // The totals count what was compared, so that identical + differing
        // always adds up against them and the excluded entries are visible as
        // their own number rather than as an unexplained gap.
        return [$diffs, $identical, count($mapA), count($mapB), $hits];
    }

    /**
     * @return array{0: list<MediaDiff>, 1: int, 2: int, 3: int, 4: array<string, int>}
     */
    private function diffMedia(
        PublicationChannel $a,
        PublicationChannel $b,
        ChannelScope $scope,
        ResourceExclusionList $exclusions,
    ): array {
        $mapA = $this->enumerator->media($a, $scope);
        $mapB = $this->enumerator->media($b, $scope);

        $diffs = [];
        $identical = 0;
        $hits = self::noHits($exclusions);

        foreach ($this->unionKeys($mapA, $mapB) as $key) {
            $pattern = $exclusions->matchMedia($key);
            if ($pattern !== null) {
                $hits[$pattern]++;
                unset($mapA[$key], $mapB[$key]);
                continue;
            }

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

        return [$diffs, $identical, count($mapA), count($mapB), $hits];
    }

    /**
     * A zeroed hit counter per pattern, so that a pattern which never matches
     * still shows up in the report - as the unused rule it is.
     *
     * @return array<string, int>
     */
    private static function noHits(ResourceExclusionList $exclusions): array
    {
        return array_fill_keys($exclusions->patterns(), 0);
    }

    /**
     * @param array<string, int> $resourceHits
     * @param array<string, int> $mediaHits
     * @return list<ExclusionStat>
     */
    private static function exclusionStats(
        ResourceExclusionList $exclusions,
        array $resourceHits,
        array $mediaHits,
    ): array {
        $stats = [];
        foreach ($exclusions->patterns() as $pattern) {
            $stats[] = new ExclusionStat(
                $pattern,
                $resourceHits[$pattern] ?? 0,
                $mediaHits[$pattern] ?? 0,
            );
        }

        return $stats;
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
