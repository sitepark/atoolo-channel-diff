<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Tests\Diff;

use Atoolo\ChannelDiff\Diff\IgnoreList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IgnoreList::class)]
final class IgnoreListTest extends TestCase
{
    public function testExactAndSubtreeMatches(): void
    {
        $list = new IgnoreList(['version', 'base.date']);

        self::assertTrue($list->isIgnored('version'));
        self::assertTrue($list->isIgnored('base.date'));
        self::assertTrue($list->isIgnored('base.date.inner'));
        self::assertFalse($list->isIgnored('versionOther'));
        self::assertFalse($list->isIgnored('base'));
        self::assertFalse($list->isIgnored('base.title'));
    }

    public function testDefaultContainsVolatileFields(): void
    {
        $list = IgnoreList::default();
        self::assertTrue($list->isIgnored('version'));
        self::assertTrue($list->isIgnored('changed'));
    }

    public function testWithAdditionalMergesAndDeduplicates(): void
    {
        $list = (new IgnoreList(['a']))->withAdditional(['a', 'b', ' ']);
        self::assertSame(['a', 'b'], $list->paths());
    }

    public function testWildcardSegmentMatchesAnySingleKey(): void
    {
        $list = new IgnoreList(['a.*.c']);

        self::assertTrue($list->isIgnored('a.x.c'));
        self::assertTrue($list->isIgnored('a.y.c'));
        self::assertTrue($list->isIgnored('a.x.c.deep'));
        self::assertFalse($list->isIgnored('a.x.y'));
        self::assertFalse($list->isIgnored('a.c'));
    }

    public function testTerminalWildcardIsNormalizationMarkerNotIgnore(): void
    {
        $list = new IgnoreList(['a.b.*']);

        // A terminal * marks key normalization; it does not skip paths.
        self::assertFalse($list->isIgnored('a.b.x'));
        self::assertTrue($list->isKeyNormalized('a.b'));
        self::assertFalse($list->isKeyNormalized('a'));
        self::assertFalse($list->isKeyNormalized('a.b.x'));
    }

    public function testKeyNormalizationMarkerSupportsWildcardBase(): void
    {
        $list = new IgnoreList(['a.*.c.*']);

        self::assertTrue($list->isKeyNormalized('a.x.c'));
        self::assertFalse($list->isKeyNormalized('a.x.d'));
    }

    public function testGlobstarMatchesAnyDepth(): void
    {
        $list = new IgnoreList(['**.geo.features.*.id']);

        self::assertTrue($list->isIgnored('base.geo.features.x.id'));
        self::assertTrue($list->isIgnored('content.items.0.model.geo.features.y.id'));
        self::assertTrue($list->isIgnored('geo.features.z.id'));
        self::assertFalse($list->isIgnored('base.geo.features.x.name'));
    }

    public function testGlobstarNormalizationMarkerMatchesAnyDepth(): void
    {
        $list = new IgnoreList(['**.geo.features.*']);

        self::assertTrue($list->isKeyNormalized('base.germanCourse.venue.addressData.geo.features'));
        self::assertTrue($list->isKeyNormalized('content.items.0.items.4.model.addressData.geo.features'));
        self::assertFalse($list->isKeyNormalized('base.geo'));
        // Terminal * remains a normalization marker, not an ignore.
        self::assertFalse($list->isIgnored('base.geo.features.x'));
    }
}
