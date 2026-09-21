<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Tests\Rules;

use Atoolo\ChannelDiff\Rules\RuleSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuleSet::class)]
final class RuleSetTest extends TestCase
{
    public function testDefaultIsEmpty(): void
    {
        $rules = new RuleSet();

        self::assertTrue($rules->isEmpty());
        self::assertSame([], $rules->excludes);
        self::assertSame([], $rules->excludeResources);
        self::assertNull($rules->floatPrecision);
        self::assertNull($rules->floatTolerance());
    }

    public function testExcludesAloneMakeItNonEmpty(): void
    {
        self::assertFalse((new RuleSet(['a.b']))->isEmpty());
        self::assertFalse((new RuleSet(excludeResources: ['page.php']))->isEmpty());
        self::assertFalse((new RuleSet(floatPrecision: 7))->isEmpty());
    }

    public function testMergeAccumulatesExcludesWithoutDuplicates(): void
    {
        $merged = (new RuleSet(['a.b', 'c.d']))->merge(new RuleSet(['c.d', 'e.f']));

        self::assertSame(['a.b', 'c.d', 'e.f'], $merged->excludes);
    }

    public function testMergeAccumulatesResourceExclusionsWithoutDuplicates(): void
    {
        $merged = (new RuleSet(excludeResources: ['a.php', 'b.php']))
            ->merge(new RuleSet(excludeResources: ['b.php', 'c.php']));

        self::assertSame(['a.php', 'b.php', 'c.php'], $merged->excludeResources);
    }

    public function testMergeLetsTheLaterFloatPrecisionWin(): void
    {
        $merged = (new RuleSet(floatPrecision: 7))->merge(new RuleSet(floatPrecision: 4));

        self::assertSame(4, $merged->floatPrecision);
    }

    public function testMergeKeepsTheEarlierFloatPrecisionWhenTheLaterIsUnset(): void
    {
        $merged = (new RuleSet(floatPrecision: 7))->merge(new RuleSet(['a.b']));

        self::assertSame(7, $merged->floatPrecision);
    }

    public function testMergeCollectsSourcesWithoutDuplicates(): void
    {
        $merged = (new RuleSet(sources: ['/a.yaml']))
            ->merge(new RuleSet(sources: ['/a.yaml', '/b.yaml']));

        self::assertSame(['/a.yaml', '/b.yaml'], $merged->sources);
    }

    public function testFloatToleranceIsHalfAUnitOfTheLastPlace(): void
    {
        self::assertSame(0.5, (new RuleSet(floatPrecision: 0))->floatTolerance());
        self::assertSame(5.0e-8, (new RuleSet(floatPrecision: 7))->floatTolerance());
        self::assertSame(5.0e-3, (new RuleSet(floatPrecision: 2))->floatTolerance());
    }

    /**
     * The focalpoint values observed in a real re-publish: the new publisher
     * writes 8 decimals, but the 8th digit is not a correct rounding of the old
     * value, so a precision of 7 is what actually makes them equal.
     */
    public function testToleranceCoversObservedRepublishDeviations(): void
    {
        $observed = [
            [0.49298245614035086, 0.49298245],
            [0.4026315789473684, 0.40263158],
            [0.40304182509505704, 0.40304184],
            [0.75206611570248, 0.75206614],
            [0.74380165289256, 0.74380165],
            [0.31129476584022, 0.31129476],
        ];

        $tolerance = (new RuleSet(floatPrecision: 7))->floatTolerance();
        self::assertNotNull($tolerance);

        foreach ($observed as [$old, $new]) {
            self::assertLessThanOrEqual(
                $tolerance,
                abs($old - $new),
                sprintf('%.17g vs %.17g should be within tolerance', $old, $new),
            );
        }
    }
}
