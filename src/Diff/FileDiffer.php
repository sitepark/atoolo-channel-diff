<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Diff;

use Closure;
use JsonException;

/**
 * Compares two non-PHP files by content.
 *
 *  - Binary files (NUL byte or invalid UTF-8) are compared by sha256.
 *  - JSON files are parsed and diffed structurally with the same rules as the
 *    resources; a file that does not parse falls back to the text diff.
 *  - Everything else is compared line by line.
 */
final class FileDiffer
{
    /** Media larger than this are only hashed: a text diff would not be read. */
    public const MAX_TEXT_MEDIA_BYTES = 1048576;

    public function __construct(
        private readonly TextDiffer $textDiffer = new TextDiffer(),
    ) {}

    /**
     * @param Closure(array<array-key, mixed>, array<array-key, mixed>): list<FieldDiff> $compareArrays
     * @param bool $maskHashes mask the hash part of `user:hash` lines before
     *        comparing (text files only)
     * @return ResourceDiff|null null if the files are equal
     */
    public function compare(
        string $key,
        string $fileA,
        string $fileB,
        Closure $compareArrays,
        bool $maskHashes = false,
    ): ?ResourceDiff {
        $contentA = file_get_contents($fileA);
        $contentB = file_get_contents($fileB);
        if ($contentA === false || $contentB === false) {
            return new ResourceDiff($key, EntryStatus::READ_ERROR, error: 'file could not be read');
        }

        if (self::isBinary($contentA) || self::isBinary($contentB)) {
            if ($contentA === $contentB) {
                return null;
            }

            return new ResourceDiff($key, EntryStatus::CHANGED, textDiff: sprintf(
                'binary files differ (sha256 %s … ≠ %s …)',
                substr(hash('sha256', $contentA), 0, 12),
                substr(hash('sha256', $contentB), 0, 12),
            ));
        }

        $structured = $this->compareJson($key, $contentA, $contentB, $compareArrays);
        if ($structured !== false) {
            return $structured;
        }

        if ($maskHashes) {
            $contentA = self::maskHashes($contentA);
            $contentB = self::maskHashes($contentB);
        }

        $text = $this->textDiffer->diff($contentA, $contentB);

        return $text === ''
            ? null
            : new ResourceDiff($key, EntryStatus::CHANGED, textDiff: $text);
    }

    /**
     * The line diff of two text files, for a media file whose hashes differ.
     *
     * @return string|null null if a file cannot be read, is binary or larger
     *         than {@see self::MAX_TEXT_MEDIA_BYTES}
     */
    public function textDiff(string $fileA, string $fileB): ?string
    {
        foreach ([$fileA, $fileB] as $file) {
            $size = @filesize($file);
            if ($size === false || $size > self::MAX_TEXT_MEDIA_BYTES) {
                return null;
            }
        }
        $contentA = file_get_contents($fileA);
        $contentB = file_get_contents($fileB);
        if ($contentA === false || $contentB === false
            || self::isBinary($contentA) || self::isBinary($contentB)) {
            return null;
        }

        return $this->textDiffer->diff($contentA, $contentB);
    }

    /**
     * Replaces everything after the first colon of each `user:hash` line, so
     * that entries differing only in a randomly salted hash compare equal.
     * Comment lines and lines without a colon stay untouched.
     */
    public static function maskHashes(string $content): string
    {
        $masked = preg_replace('/^([^#:\n][^:\n]*):[^\n]*$/m', '$1:<hash>', $content);

        return $masked ?? $content;
    }

    /**
     * @param Closure(array<array-key, mixed>, array<array-key, mixed>): list<FieldDiff> $compareArrays
     * @return ResourceDiff|null|false false if the files are not both JSON
     *         objects/lists, so that the caller falls back to the text diff
     */
    private function compareJson(
        string $key,
        string $contentA,
        string $contentB,
        Closure $compareArrays,
    ): ResourceDiff|false|null {
        if (!str_ends_with(strtolower($key), '.json')) {
            return false;
        }

        try {
            $dataA = json_decode($contentA, true, 512, JSON_THROW_ON_ERROR);
            $dataB = json_decode($contentB, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }
        if (!is_array($dataA) || !is_array($dataB)) {
            return false;
        }

        $fieldDiffs = $compareArrays($dataA, $dataB);

        return $fieldDiffs === []
            ? null
            : new ResourceDiff($key, EntryStatus::CHANGED, fieldDiffs: $fieldDiffs);
    }

    private static function isBinary(string $content): bool
    {
        return str_contains($content, "\0") || !mb_check_encoding($content, 'UTF-8');
    }
}
