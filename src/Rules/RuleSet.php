<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Rules;

/**
 * Accepted differences, in generalized form: field paths that should not be
 * reported, whole resources that should not be compared at all, and the
 * precision at which floats still count as equal.
 *
 * Rules are collected from rule files next to the compared channels and from
 * the command line, then merged into a single set.
 */
final class RuleSet
{
    /**
     * @param list<string> $excludes dot-notation field paths (wildcards allowed)
     * @param list<string> $excludeResources slash-notation resource paths
     *        (wildcards allowed); a matched resource is left out of the
     *        comparison entirely, together with its media
     * @param int|null $floatPrecision number of decimal places at which two
     *        floats still count as equal; null keeps the strict comparison
     * @param list<string> $sources rule files this set was built from, for
     *        reporting which rules were in effect
     * @param bool $numericStringsEqualNumbers when true, a numeric string and
     *        the same number count as equal ("600" == 600). Unlike an exclude
     *        this blinds no field: a value that really changed is still
     *        reported, only the notation may differ. Off by default, because
     *        a type change is worth seeing until someone has decided it.
     */
    public function __construct(
        public readonly array $excludes = [],
        public readonly array $excludeResources = [],
        public readonly ?int $floatPrecision = null,
        public readonly array $sources = [],
        public readonly bool $numericStringsEqualNumbers = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->excludes === []
            && $this->excludeResources === []
            && $this->floatPrecision === null
            && !$this->numericStringsEqualNumbers;
    }

    /**
     * Merges $other on top of this set: excludes accumulate, a float precision
     * in $other wins. Callers therefore pass the more specific set last (rule
     * file first, command line afterwards).
     *
     * numericStringsEqualNumbers accumulates rather than being overwritten,
     * so that a set carrying only another rule - as the command line builds
     * one per option - does not silently switch it off again.
     */
    public function merge(self $other): self
    {
        return new self(
            array_values(array_unique([...$this->excludes, ...$other->excludes])),
            array_values(array_unique([...$this->excludeResources, ...$other->excludeResources])),
            $other->floatPrecision ?? $this->floatPrecision,
            array_values(array_unique([...$this->sources, ...$other->sources])),
            $this->numericStringsEqualNumbers || $other->numericStringsEqualNumbers,
        );
    }

    /**
     * The maximum absolute difference at which two floats still count as equal,
     * derived from the configured number of decimal places: values that agree
     * on that many decimals differ by at most half a unit of the last place.
     *
     * A tolerance is used rather than rounding both sides, so two values that
     * happen to straddle a rounding boundary are not reported as a difference.
     */
    public function floatTolerance(): ?float
    {
        if ($this->floatPrecision === null) {
            return null;
        }

        return 0.5 * (10 ** -$this->floatPrecision);
    }
}
