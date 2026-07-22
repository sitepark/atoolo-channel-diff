<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Diff;

/**
 * Recursively compares two nested resource arrays and returns the list of
 * differing field paths, honouring an {@see IgnoreList}.
 */
final class ArrayDiffer
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const UUID_SENTINEL = "\0uuid\0";

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @param bool $nullEqualsMissing When true (default), a field that is null
     *        on one side and absent on the other is treated as equal.
     * @param bool $emptyStringEqualsMissing When true (default), a field that is
     *        an empty string on one side and absent on the other is treated as
     *        equal.
     * @param bool $emptyArrayEqualsMissing When true (default), a field that is
     *        an empty array (recursively, i.e. an array whose nested values are
     *        all empty) on one side and absent on the other is treated as equal.
     * @param bool $normalizeUuidKeys When true (default), arrays whose keys are
     *        all UUIDs are matched by the content of their values instead of by
     *        their (volatile) keys; inner values equal to the key (e.g. a
     *        mirrored "id") are neutralized so entries can pair up.
     * @return list<FieldDiff>
     */
    public function diff(
        array $a,
        array $b,
        IgnoreList $ignore,
        bool $nullEqualsMissing = true,
        bool $emptyStringEqualsMissing = true,
        bool $emptyArrayEqualsMissing = true,
        bool $normalizeUuidKeys = true,
    ): array {
        $context = new DiffContext(
            $ignore,
            $nullEqualsMissing,
            $emptyStringEqualsMissing,
            $emptyArrayEqualsMissing,
            $normalizeUuidKeys,
        );

        $diffs = [];
        $this->compare('', $a, $b, $context, $diffs);

        usort($diffs, static fn(FieldDiff $x, FieldDiff $y): int => strcmp($x->path, $y->path));

        return $diffs;
    }

    /**
     * @param array<array-key, mixed> $a
     * @param array<array-key, mixed> $b
     * @param list<FieldDiff> $diffs
     */
    private function compare(string $prefix, array $a, array $b, DiffContext $context, array &$diffs): void
    {
        if ($context->ignore->isKeyNormalized($prefix)) {
            $this->compareNormalized($prefix, $a, $b, $context, $diffs);
            return;
        }

        if ($context->normalizeUuidKeys && $this->isUuidKeyed($a) && $this->isUuidKeyed($b)) {
            $this->compareUuidNormalized($prefix, $a, $b, $context, $diffs);
            return;
        }

        /** @var array<array-key, true> $keys */
        $keys = $a + $b;

        foreach (array_keys($keys) as $key) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if ($context->ignore->isIgnored($path)) {
                continue;
            }

            $inA = array_key_exists($key, $a);
            $inB = array_key_exists($key, $b);

            if ($inA && !$inB) {
                if ($this->treatedAsMissing($a[$key], $context)) {
                    continue;
                }
                $diffs[] = new FieldDiff($path, ChangeType::REMOVED, $a[$key], null);
                continue;
            }
            if (!$inA && $inB) {
                if ($this->treatedAsMissing($b[$key], $context)) {
                    continue;
                }
                $diffs[] = new FieldDiff($path, ChangeType::ADDED, null, $b[$key]);
                continue;
            }

            $this->diffValue($path, $a[$key], $b[$key], $context, $diffs);
        }
    }

    /**
     * Compares two values (arrays recurse, everything else is compared by
     * {@see valuesEqual()}), appending any differences to $diffs.
     *
     * @param list<FieldDiff> $diffs
     */
    private function diffValue(string $path, mixed $a, mixed $b, DiffContext $context, array &$diffs): void
    {
        if (is_array($a) && is_array($b)) {
            $this->compare($path, $a, $b, $context, $diffs);
            return;
        }

        if (!$this->valuesEqual($a, $b)) {
            $diffs[] = new FieldDiff($path, ChangeType::CHANGED, $a, $b);
        }
    }

    /**
     * Compares an array whose keys are volatile (marked via a terminal `*`):
     * values are matched by content instead of by key.
     *
     * @param array<array-key, mixed> $a
     * @param array<array-key, mixed> $b
     * @param list<FieldDiff> $diffs
     */
    private function compareNormalized(string $prefix, array $a, array $b, DiffContext $context, array &$diffs): void
    {
        $entriesA = array_map(static fn(mixed $v): array => ['orig' => $v, 'cmp' => $v], array_values($a));
        $entriesB = array_map(static fn(mixed $v): array => ['orig' => $v, 'cmp' => $v], array_values($b));

        $this->matchByContent($prefix, $entriesA, $entriesB, $context, $diffs);
    }

    /**
     * Compares an array whose keys are UUIDs by content. Before matching, each
     * value has any inner value equal to its own UUID key neutralized, so that
     * a mirrored key (e.g. "id" == the UUID) does not prevent pairing.
     *
     * @param array<array-key, mixed> $a
     * @param array<array-key, mixed> $b
     * @param list<FieldDiff> $diffs
     */
    private function compareUuidNormalized(string $prefix, array $a, array $b, DiffContext $context, array &$diffs): void
    {
        $entriesA = [];
        foreach ($a as $key => $value) {
            $entriesA[] = ['orig' => $value, 'cmp' => $this->neutralizeUuid($value, (string) $key)];
        }
        $entriesB = [];
        foreach ($b as $key => $value) {
            $entriesB[] = ['orig' => $value, 'cmp' => $this->neutralizeUuid($value, (string) $key)];
        }

        $this->matchByContent($prefix, $entriesA, $entriesB, $context, $diffs);
    }

    /**
     * Pairs each A entry with a still-unused B entry that compares equal (no
     * field diffs) under the child path "$prefix.*"; unpaired entries are
     * reported as removed/added at $prefix. Entries carry an "orig" value (for
     * reporting) and a "cmp" value (used for matching).
     *
     * @param list<array{orig: mixed, cmp: mixed}> $entriesA
     * @param list<array{orig: mixed, cmp: mixed}> $entriesB
     * @param list<FieldDiff> $diffs
     */
    private function matchByContent(string $prefix, array $entriesA, array $entriesB, DiffContext $context, array &$diffs): void
    {
        $childPath = $prefix === '' ? '*' : $prefix . '.*';

        /** @var array<int, true> $usedB */
        $usedB = [];

        foreach ($entriesA as $entryA) {
            $matchIndex = null;
            foreach ($entriesB as $i => $entryB) {
                if (isset($usedB[$i])) {
                    continue;
                }
                $candidate = [];
                $this->diffValue($childPath, $entryA['cmp'], $entryB['cmp'], $context, $candidate);
                if ($candidate === []) {
                    $matchIndex = $i;
                    break;
                }
            }

            if ($matchIndex !== null) {
                $usedB[$matchIndex] = true;
            } else {
                $diffs[] = new FieldDiff($prefix, ChangeType::REMOVED, $entryA['orig'], null);
            }
        }

        foreach ($entriesB as $i => $entryB) {
            if (!isset($usedB[$i])) {
                $diffs[] = new FieldDiff($prefix, ChangeType::ADDED, null, $entryB['orig']);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $array
     */
    private function isUuidKeyed(array $array): bool
    {
        if ($array === []) {
            return false;
        }
        foreach (array_keys($array) as $key) {
            if (!is_string($key) || preg_match(self::UUID_PATTERN, $key) !== 1) {
                return false;
            }
        }
        return true;
    }

    /**
     * Recursively replaces any string value equal to $uuid with a fixed
     * sentinel, so two entries keyed by different UUIDs that mirror their key
     * internally become comparable.
     */
    private function neutralizeUuid(mixed $value, string $uuid): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = $this->neutralizeUuid($item, $uuid);
            }
            return $out;
        }
        if (is_string($value) && $value === $uuid) {
            return self::UUID_SENTINEL;
        }
        return $value;
    }

    /**
     * Whether a value present only on one side counts as "missing" (and thus
     * equal to the absent side).
     *
     * The empty-array rule is recursive: an array counts as empty when all of
     * its elements are themselves treated as missing. So an associative array
     * whose (nested) values are all empty is considered empty as a whole, e.g.
     * ['features' => ['primary' => []], 'wkt' => ['primary' => []]].
     */
    private function treatedAsMissing(mixed $value, DiffContext $context): bool
    {
        if ($context->nullEqualsMissing && $value === null) {
            return true;
        }
        if ($context->emptyStringEqualsMissing && $value === '') {
            return true;
        }
        if ($context->emptyArrayEqualsMissing && is_array($value)) {
            foreach ($value as $item) {
                if (!$this->treatedAsMissing($item, $context)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    /**
     * Objects from the resource scripts are compared structurally rather than
     * by instance identity, since each read produces fresh instances.
     * Closures (e.g. from "code" content sections) are not value-comparable, so
     * their source text is compared instead. Scalars are compared strictly.
     */
    private function valuesEqual(mixed $a, mixed $b): bool
    {
        if ($a instanceof \Closure || $b instanceof \Closure) {
            return $a instanceof \Closure
                && $b instanceof \Closure
                && $this->closureSource($a) === $this->closureSource($b);
        }

        if (is_object($a) && is_object($b)) {
            return $a == $b;
        }

        return $a === $b;
    }

    private function closureSource(\Closure $closure): string
    {
        $reflection = new \ReflectionFunction($closure);
        $file = $reflection->getFileName();
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();

        if ($file === false || $start === false || $end === false) {
            return '';
        }

        $lines = @file($file);
        if ($lines === false) {
            return '';
        }

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
