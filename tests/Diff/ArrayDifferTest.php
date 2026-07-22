<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Tests\Diff;

use Atoolo\ChannelDiff\Diff\ArrayDiffer;
use Atoolo\ChannelDiff\Diff\ChangeType;
use Atoolo\ChannelDiff\Diff\IgnoreList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArrayDiffer::class)]
final class ArrayDifferTest extends TestCase
{
    private ArrayDiffer $differ;

    protected function setUp(): void
    {
        $this->differ = new ArrayDiffer();
    }

    public function testEqualArraysProduceNoDiff(): void
    {
        $a = ['x' => 1, 'nested' => ['y' => 2]];
        $diffs = $this->differ->diff($a, $a, new IgnoreList([]));
        self::assertSame([], $diffs);
    }

    public function testDetectsAddedRemovedAndChanged(): void
    {
        $a = ['keep' => 'x', 'title' => 'Alt', 'removed' => 'gone'];
        $b = ['keep' => 'x', 'title' => 'Neu', 'added' => 'new'];

        $diffs = $this->differ->diff($a, $b, new IgnoreList([]));

        $byPath = [];
        foreach ($diffs as $diff) {
            $byPath[$diff->path] = $diff;
        }

        self::assertCount(3, $diffs);
        self::assertSame(ChangeType::CHANGED, $byPath['title']->type);
        self::assertSame('Alt', $byPath['title']->oldValue);
        self::assertSame('Neu', $byPath['title']->newValue);
        self::assertSame(ChangeType::REMOVED, $byPath['removed']->type);
        self::assertSame(ChangeType::ADDED, $byPath['added']->type);
    }

    public function testNestedPathsUseDotNotation(): void
    {
        $a = ['base' => ['meta' => ['title' => 'A']]];
        $b = ['base' => ['meta' => ['title' => 'B']]];

        $diffs = $this->differ->diff($a, $b, new IgnoreList([]));

        self::assertCount(1, $diffs);
        self::assertSame('base.meta.title', $diffs[0]->path);
    }

    public function testIgnoredPathsAreSkippedIncludingSubtree(): void
    {
        $a = ['version' => '1', 'searchindexdata' => ['content' => 'a'], 'title' => 'A'];
        $b = ['version' => '2', 'searchindexdata' => ['content' => 'b'], 'title' => 'A'];

        $diffs = $this->differ->diff($a, $b, new IgnoreList(['version', 'searchindexdata']));

        self::assertSame([], $diffs);
    }

    public function testEqualObjectsOfDifferentInstancesAreNotChanged(): void
    {
        $a = ['o' => (object) ['p' => 1]];
        $b = ['o' => (object) ['p' => 1]];

        $diffs = $this->differ->diff($a, $b, new IgnoreList([]));

        self::assertSame([], $diffs);
    }

    public function testDifferentObjectsAreChanged(): void
    {
        $a = ['o' => (object) ['p' => 1]];
        $b = ['o' => (object) ['p' => 2]];

        $diffs = $this->differ->diff($a, $b, new IgnoreList([]));

        self::assertCount(1, $diffs);
        self::assertSame('o', $diffs[0]->path);
    }

    public function testNullFieldEqualsMissingFieldByDefault(): void
    {
        // null in A, absent in B -> equal by default (both directions).
        self::assertSame([], $this->differ->diff(['x' => null], [], new IgnoreList([])));
        self::assertSame([], $this->differ->diff([], ['x' => null], new IgnoreList([])));
    }

    public function testNullFieldDiffersFromMissingFieldInStrictMode(): void
    {
        $removed = $this->differ->diff(['x' => null], [], new IgnoreList([]), nullEqualsMissing: false);
        self::assertCount(1, $removed);
        self::assertSame(ChangeType::REMOVED, $removed[0]->type);

        $added = $this->differ->diff([], ['x' => null], new IgnoreList([]), nullEqualsMissing: false);
        self::assertCount(1, $added);
        self::assertSame(ChangeType::ADDED, $added[0]->type);
    }

    public function testMissingFieldWithNonNullValueIsAlwaysADifference(): void
    {
        // Default mode: a non-null value present only on one side still differs.
        $diffs = $this->differ->diff(['x' => 'value'], [], new IgnoreList([]));
        self::assertCount(1, $diffs);
        self::assertSame(ChangeType::REMOVED, $diffs[0]->type);
    }

    public function testEmptyStringFieldEqualsMissingFieldByDefault(): void
    {
        self::assertSame([], $this->differ->diff(['x' => ''], [], new IgnoreList([])));
        self::assertSame([], $this->differ->diff([], ['x' => ''], new IgnoreList([])));
    }

    public function testEmptyStringFieldDiffersFromMissingFieldInStrictMode(): void
    {
        $diffs = $this->differ->diff(
            ['x' => ''],
            [],
            new IgnoreList([]),
            emptyStringEqualsMissing: false,
        );

        self::assertCount(1, $diffs);
        self::assertSame(ChangeType::REMOVED, $diffs[0]->type);
    }

    public function testStrictNullDoesNotAffectEmptyStringLeniency(): void
    {
        // Empty string still equals missing even when null strictness is on.
        $diffs = $this->differ->diff(
            ['x' => ''],
            [],
            new IgnoreList([]),
            nullEqualsMissing: false,
        );

        self::assertSame([], $diffs);
    }

    public function testEmptyArrayFieldEqualsMissingFieldByDefault(): void
    {
        self::assertSame([], $this->differ->diff(['x' => []], [], new IgnoreList([])));
        self::assertSame([], $this->differ->diff([], ['x' => []], new IgnoreList([])));
    }

    public function testEmptyArrayFieldDiffersFromMissingFieldInStrictMode(): void
    {
        $diffs = $this->differ->diff(
            ['x' => []],
            [],
            new IgnoreList([]),
            emptyArrayEqualsMissing: false,
        );

        self::assertCount(1, $diffs);
        self::assertSame(ChangeType::REMOVED, $diffs[0]->type);
    }

    public function testNonEmptyArrayOnlyOnOneSideIsAlwaysADifference(): void
    {
        $diffs = $this->differ->diff(['x' => ['a']], [], new IgnoreList([]));

        self::assertCount(1, $diffs);
        self::assertSame(ChangeType::REMOVED, $diffs[0]->type);
    }

    public function testRecursivelyEmptyAssociativeArrayEqualsMissingByDefault(): void
    {
        $geo = ['geo' => [
            'features' => ['primary' => []],
            'wkt' => ['primary' => []],
        ]];

        self::assertSame([], $this->differ->diff($geo, [], new IgnoreList([])));
        self::assertSame([], $this->differ->diff([], $geo, new IgnoreList([])));
    }

    public function testRecursivelyEmptyArrayWithANonEmptyLeafIsADifference(): void
    {
        $geo = ['geo' => [
            'features' => ['primary' => []],
            'wkt' => ['primary' => ['POINT(1 2)']],
        ]];

        $diffs = $this->differ->diff($geo, [], new IgnoreList([]));

        self::assertCount(1, $diffs);
        self::assertSame('geo', $diffs[0]->path);
    }

    public function testRecursivelyEmptyArrayIsADifferenceInStrictMode(): void
    {
        $geo = ['geo' => ['features' => ['primary' => []]]];

        $diffs = $this->differ->diff(
            $geo,
            [],
            new IgnoreList([]),
            emptyArrayEqualsMissing: false,
        );

        self::assertCount(1, $diffs);
        self::assertSame('geo', $diffs[0]->path);
    }

    /**
     * @param array<int, float> $coordinates
     * @return array<string, mixed>
     */
    private function geo(string $uuid, array $coordinates): array
    {
        return ['geo' => ['features' => ['primary' => [
            $uuid => [
                'id' => $uuid,
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => $coordinates],
            ],
        ]]]];
    }

    public function testVolatileUuidKeysDifferWithoutNormalization(): void
    {
        $a = $this->geo('uuid-a', [9.1, 48.7]);
        $b = $this->geo('uuid-b', [9.1, 48.7]);

        $diffs = $this->differ->diff($a, $b, new IgnoreList([]));

        // Same content, but the differing UUID key yields removed + added.
        self::assertCount(2, $diffs);
    }

    public function testNormalizedUuidKeysWithIgnoredInnerIdAreEqual(): void
    {
        $a = $this->geo('uuid-a', [9.1, 48.7]);
        $b = $this->geo('uuid-b', [9.1, 48.7]);

        $ignore = new IgnoreList([
            'geo.features.primary.*',
            'geo.features.primary.*.id',
        ]);

        self::assertSame([], $this->differ->diff($a, $b, $ignore));
    }

    public function testNormalizedUuidKeysStillDetectContentChange(): void
    {
        $a = $this->geo('uuid-a', [9.1, 48.7]);
        $b = $this->geo('uuid-b', [9.2, 48.7]);

        $ignore = new IgnoreList([
            'geo.features.primary.*',
            'geo.features.primary.*.id',
        ]);

        $diffs = $this->differ->diff($a, $b, $ignore);

        // Coordinates changed -> the entries no longer match -> removed + added.
        self::assertCount(2, $diffs);
        self::assertSame('geo.features.primary', $diffs[0]->path);
    }

    public function testGlobstarNormalizesUuidKeysAtAnyDepth(): void
    {
        // Same feature content and volatile uuid key, but nested deep under an
        // arbitrary path -> a single "**"-prefixed pattern must still match it.
        $feature = static fn(string $uuid): array => ['content' => ['items' => [
            ['model' => ['addressData' => ['geo' => ['features' => [
                $uuid => ['id' => $uuid, 'geometry' => ['coordinates' => [9.1, 48.7]]],
            ]]]]],
        ]]];

        $ignore = new IgnoreList(['**.geo.features.*', '**.geo.features.*.id']);

        self::assertSame([], $this->differ->diff($feature('uuid-a'), $feature('uuid-b'), $ignore));
    }

    public function testNormalizedLevelMatchesMultipleEntriesAsMultiset(): void
    {
        $ignore = new IgnoreList([
            'geo.features.primary.*',
            'geo.features.primary.*.id',
        ]);

        $a = ['geo' => ['features' => ['primary' => [
            'a1' => ['id' => 'a1', 'geometry' => ['coordinates' => [1.0, 2.0]]],
            'a2' => ['id' => 'a2', 'geometry' => ['coordinates' => [3.0, 4.0]]],
        ]]]];
        // Same two features, different (volatile) keys and ids, swapped order.
        $b = ['geo' => ['features' => ['primary' => [
            'b2' => ['id' => 'b2', 'geometry' => ['coordinates' => [3.0, 4.0]]],
            'b1' => ['id' => 'b1', 'geometry' => ['coordinates' => [1.0, 2.0]]],
        ]]]];

        self::assertSame([], $this->differ->diff($a, $b, $ignore));
    }

    private const UUID_A = '11111111-1111-1111-1111-111111111111';
    private const UUID_B = '22222222-2222-2222-2222-222222222222';

    /**
     * @param array<int, float> $coordinates
     * @return array<string, mixed>
     */
    private function uuidFeature(string $uuid, array $coordinates): array
    {
        return ['features' => [$uuid => [
            'id' => $uuid,
            'type' => 'Feature',
            'geometry' => ['coordinates' => $coordinates],
        ]]];
    }

    public function testUuidKeyedArraysAreNormalizedByDefault(): void
    {
        // Same content, only the volatile UUID key (and mirrored inner id)
        // differs -> equal without any ignore configuration.
        $a = $this->uuidFeature(self::UUID_A, [9.1, 48.7]);
        $b = $this->uuidFeature(self::UUID_B, [9.1, 48.7]);

        self::assertSame([], $this->differ->diff($a, $b, new IgnoreList([])));
    }

    public function testUuidKeyedArraysDifferWithStrictUuidKeys(): void
    {
        $a = $this->uuidFeature(self::UUID_A, [9.1, 48.7]);
        $b = $this->uuidFeature(self::UUID_B, [9.1, 48.7]);

        $diffs = $this->differ->diff($a, $b, new IgnoreList([]), normalizeUuidKeys: false);

        self::assertCount(2, $diffs);
    }

    public function testUuidNormalizationStillDetectsContentChange(): void
    {
        $a = $this->uuidFeature(self::UUID_A, [9.1, 48.7]);
        $b = $this->uuidFeature(self::UUID_B, [1.0, 2.0]);

        $diffs = $this->differ->diff($a, $b, new IgnoreList([]));

        self::assertCount(2, $diffs);
        self::assertSame('features', $diffs[0]->path);
    }

    public function testUuidNormalizationPairsEntriesDespiteEmptyExtraField(): void
    {
        // Mirrors real data where one channel adds "properties" => [].
        $a = ['features' => [self::UUID_A => ['id' => self::UUID_A, 'geometry' => ['coordinates' => [1.0, 2.0]]]]];
        $b = ['features' => [self::UUID_B => ['id' => self::UUID_B, 'geometry' => ['coordinates' => [1.0, 2.0]], 'properties' => []]]];

        self::assertSame([], $this->differ->diff($a, $b, new IgnoreList([])));
    }

    public function testUuidNormalizationWorksAtAnyDepthWithoutConfiguration(): void
    {
        $wrap = fn(string $uuid): array => ['content' => ['items' => [
            ['model' => ['addressData' => ['geo' => $this->uuidFeature($uuid, [1.0, 2.0])]]],
        ]]];

        self::assertSame([], $this->differ->diff($wrap(self::UUID_A), $wrap(self::UUID_B), new IgnoreList([])));
    }

    public function testTheSameClosureIsNotChanged(): void
    {
        // A closure compared against itself has identical source -> no diff.
        // (Equality of equal closures across two files is covered by the
        // ChannelDiffer integration test.)
        $closure = static fn(): string => 'x';
        $diffs = $this->differ->diff(['fn' => $closure], ['fn' => $closure], new IgnoreList([]));

        self::assertSame([], $diffs);
    }

    public function testClosuresWithDifferentSourceAreChanged(): void
    {
        $a = ['fn' => static fn(): string => 'alpha'];
        $b = ['fn' => static fn(): string => 'beta-differs'];

        $diffs = $this->differ->diff($a, $b, new IgnoreList([]));

        self::assertCount(1, $diffs);
        self::assertSame('fn', $diffs[0]->path);
    }
}
