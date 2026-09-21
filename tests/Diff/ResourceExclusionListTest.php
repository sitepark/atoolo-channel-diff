<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Tests\Diff;

use Atoolo\ChannelDiff\Diff\ResourceExclusionList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResourceExclusionList::class)]
final class ResourceExclusionListTest extends TestCase
{
    public function testEmptyListExcludesNothing(): void
    {
        $list = new ResourceExclusionList();

        self::assertTrue($list->isEmpty());
        self::assertSame([], $list->patterns());
        self::assertNull($list->matchResource('page.php'));
        self::assertNull($list->matchMedia('page.php.media/1/a.jpg'));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function resourcePatterns(): iterable
    {
        yield 'exact match' => ['page.php', 'page.php', true];
        yield 'exact miss' => ['page.php', 'other.php', false];
        yield 'a pattern matches the whole key, not a prefix'
            => ['testseiten', 'testseiten/page.php', false];
        yield 'star stays inside one segment'
            => ['Aggregator-Test-*.php', 'Aggregator-Test-komplett.php', true];
        yield 'star does not cross a slash'
            => ['*.php', 'de/page.php', false];
        yield 'question mark matches one character'
            => ['page?.php', 'page1.php', true];
        yield 'question mark needs its character'
            => ['page?.php', 'page.php', false];
        yield 'double star spans segments'
            => ['testseiten/**', 'testseiten/a/b/page.php', true];
        yield 'double star also spans none'
            => ['**/index.php', 'index.php', true];
        yield 'double star in front of a deep key'
            => ['**/index.php', 'de/city/index.php', true];
        yield 'double star between segments'
            => ['de/**/index.php', 'de/a/b/index.php', true];
        // "**" means "any number of segments, including none" everywhere, so a
        // trailing one also matches the bare path in front of it. Consistency
        // is worth more here than an exception for the trailing position.
        yield 'a trailing double star also matches nothing at all'
            => ['testseiten/**', 'testseiten', true];
        yield 'a directory alone does not cover what is in it'
            => ['testseiten', 'testseiten/page.php', false];
    }

    #[DataProvider('resourcePatterns')]
    public function testResourcePatternMatching(string $pattern, string $key, bool $expected): void
    {
        $match = (new ResourceExclusionList([$pattern]))->matchResource($key);

        self::assertSame($expected ? $pattern : null, $match);
    }

    public function testTheFirstMatchingPatternIsReported(): void
    {
        // Attribution has to be unambiguous, otherwise the per-pattern counts
        // in the report would add up to more than what was excluded.
        $list = new ResourceExclusionList(['**/page.php', 'de/page.php']);

        self::assertSame('**/page.php', $list->matchResource('de/page.php'));
    }

    public function testPatternsAreNormalizedAndDeduplicated(): void
    {
        $list = new ResourceExclusionList([
            ' /page.php/ ',
            'page.php',
            '',
            '   ',
            'de\\city.php',
        ]);

        self::assertSame(['page.php', 'de/city.php'], $list->patterns());
    }

    public function testMediaOfAnExcludedResourceGoesWithIt(): void
    {
        $list = new ResourceExclusionList(['page.php']);

        self::assertSame('page.php', $list->matchMedia('page.php.media/41068/a.jpg'));
        self::assertSame(
            'page.php',
            $list->matchMedia('page.php.media/41068/a.jpg.scaled/deadbeef.jpg'),
        );
    }

    public function testSidecarIsRecognizedBelowADirectory(): void
    {
        $list = new ResourceExclusionList(['de/city/page.php']);

        self::assertSame(
            'de/city/page.php',
            $list->matchMedia('de/city/page.php.media/1/a.jpg'),
        );
    }

    public function testMediaOfAnotherResourceStays(): void
    {
        $list = new ResourceExclusionList(['page.php']);

        self::assertNull($list->matchMedia('other.php.media/1/a.jpg'));
        // A resource whose name merely starts like the excluded one.
        self::assertNull($list->matchMedia('page.php.backup.media/1/a.jpg'));
    }

    public function testAPatternMayNameMediaDirectly(): void
    {
        $list = new ResourceExclusionList(['img/**']);

        self::assertSame('img/**', $list->matchMedia('img/logo/a.png'));
        self::assertNull($list->matchMedia('other/logo/a.png'));
    }

    public function testASidecarWithoutAResourceNameIsNoSidecar(): void
    {
        $list = new ResourceExclusionList(['page.php']);

        self::assertNull($list->matchMedia('.media/1/a.jpg'));
    }
}
