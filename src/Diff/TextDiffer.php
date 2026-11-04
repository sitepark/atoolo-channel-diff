<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Diff;

/**
 * Line-based diff of two texts, rendered unified-like and kept concise: hunks
 * with a little context, and a cap on the number of lines printed.
 *
 * "-" lines are from channel A, "+" lines from channel B.
 */
final class TextDiffer
{
    private const CONTEXT = 2;

    /**
     * Upper bound for the cells of the LCS table. Beyond it the middle part of
     * the two texts is reported as removed/added wholesale instead of being
     * aligned, which is still correct, only less fine-grained.
     */
    private const MAX_CELLS = 4_000_000;

    private const MAX_OUTPUT_LINES = 100;

    /**
     * @return string empty if the texts are identical
     */
    public function diff(string $a, string $b): string
    {
        if ($a === $b) {
            return '';
        }

        $linesA = self::lines($a);
        $linesB = self::lines($b);
        $ops = $this->script($linesA, $linesB);
        if (!$this->hasChange($ops)) {
            // Only the trailing line break differs.
            return '@@ trailing newline differs @@';
        }

        $out = $this->hunks($ops);
        if (count($out) > self::MAX_OUTPUT_LINES) {
            $more = count($out) - self::MAX_OUTPUT_LINES;
            $out = array_slice($out, 0, self::MAX_OUTPUT_LINES);
            $out[] = sprintf('... (%d more lines)', $more);
        }

        return implode("\n", $out);
    }

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $lines = explode("\n", $text);
        if (end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * Edit script as list of [op, line, lineNoA, lineNoB], op being one of
     * " ", "-" and "+"; line numbers are 1-based.
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{0: string, 1: string, 2: int, 3: int}>
     */
    private function script(array $a, array $b): array
    {
        $countA = count($a);
        $countB = count($b);

        $start = 0;
        while ($start < $countA && $start < $countB && $a[$start] === $b[$start]) {
            $start++;
        }
        $endA = $countA;
        $endB = $countB;
        while ($endA > $start && $endB > $start && $a[$endA - 1] === $b[$endB - 1]) {
            $endA--;
            $endB--;
        }

        $ops = [];
        for ($i = 0; $i < $start; $i++) {
            $ops[] = [' ', $a[$i], $i + 1, $i + 1];
        }
        foreach ($this->middle($a, $b, $start, $endA, $endB) as $op) {
            $ops[] = $op;
        }
        for ($i = $endA, $j = $endB; $i < $countA; $i++, $j++) {
            $ops[] = [' ', $a[$i], $i + 1, $j + 1];
        }

        return $ops;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{0: string, 1: string, 2: int, 3: int}>
     */
    private function middle(array $a, array $b, int $start, int $endA, int $endB): array
    {
        $n = $endA - $start;
        $m = $endB - $start;
        $ops = [];

        if ($n * $m > self::MAX_CELLS) {
            for ($i = $start; $i < $endA; $i++) {
                $ops[] = ['-', $a[$i], $i + 1, $start + 1];
            }
            for ($j = $start; $j < $endB; $j++) {
                $ops[] = ['+', $b[$j], $start + 1, $j + 1];
            }

            return $ops;
        }

        // lcs[i][j]: length of the LCS of the tails a[start+i..] and b[start+j..]
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$start + $i] === $b[$start + $j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $i = 0;
        $j = 0;
        while ($i < $n || $j < $m) {
            $lineA = $start + $i;
            $lineB = $start + $j;
            if ($i < $n && $j < $m && $a[$lineA] === $b[$lineB]) {
                $ops[] = [' ', $a[$lineA], $lineA + 1, $lineB + 1];
                $i++;
                $j++;
            } elseif ($j >= $m || ($i < $n && $lcs[$i + 1][$j] >= $lcs[$i][$j + 1])) {
                $ops[] = ['-', $a[$lineA], $lineA + 1, $lineB + 1];
                $i++;
            } else {
                $ops[] = ['+', $b[$lineB], $lineA + 1, $lineB + 1];
                $j++;
            }
        }

        return $ops;
    }

    /**
     * @param list<array{0: string, 1: string, 2: int, 3: int}> $ops
     */
    private function hasChange(array $ops): bool
    {
        foreach ($ops as $op) {
            if ($op[0] !== ' ') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{0: string, 1: string, 2: int, 3: int}> $ops
     * @return list<string>
     */
    private function hunks(array $ops): array
    {
        $count = count($ops);
        $keep = array_fill(0, $count, false);
        foreach ($ops as $index => $op) {
            if ($op[0] === ' ') {
                continue;
            }
            $from = max(0, $index - self::CONTEXT);
            $to = min($count - 1, $index + self::CONTEXT);
            for ($k = $from; $k <= $to; $k++) {
                $keep[$k] = true;
            }
        }

        $out = [];
        $index = 0;
        while ($index < $count) {
            if (!$keep[$index]) {
                $index++;
                continue;
            }
            $first = $index;
            while ($index < $count && $keep[$index]) {
                $index++;
            }
            $hunk = array_slice($ops, $first, $index - $first);

            $removed = count(array_filter($hunk, static fn(array $o): bool => $o[0] !== '+'));
            $added = count(array_filter($hunk, static fn(array $o): bool => $o[0] !== '-'));
            $out[] = sprintf(
                '@@ -%d,%d +%d,%d @@',
                $hunk[0][2],
                $removed,
                $hunk[0][3],
                $added,
            );
            foreach ($hunk as $op) {
                $out[] = $op[0] . $op[1];
            }
        }

        return $out;
    }
}
