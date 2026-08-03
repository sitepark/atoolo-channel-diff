<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Tests\Channel;

use Atoolo\ChannelDiff\Channel\ChannelScope;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelScope::class)]
final class ChannelScopeTest extends TestCase
{
    public function testNullInputCoversWholeChannel(): void
    {
        $scope = ChannelScope::fromInput(null);

        self::assertTrue($scope->isAll());
        self::assertSame('', $scope->subPath);
    }

    public function testEmptyInputCoversWholeChannel(): void
    {
        self::assertTrue(ChannelScope::fromInput('')->isAll());
        self::assertTrue(ChannelScope::fromInput('/')->isAll());
        self::assertTrue(ChannelScope::fromInput('.')->isAll());
    }

    public function testSurroundingSlashesAreTrimmed(): void
    {
        self::assertSame('objects/de', ChannelScope::fromInput('/objects/de/')->subPath);
    }

    public function testEmptyAndCurrentDirSegmentsAreDropped(): void
    {
        self::assertSame('objects/de', ChannelScope::fromInput('objects//de')->subPath);
        self::assertSame('objects/de', ChannelScope::fromInput('./objects/./de')->subPath);
    }

    public function testBackslashesAreNormalizedToSlashes(): void
    {
        self::assertSame('objects/de', ChannelScope::fromInput('objects\\de')->subPath);
    }

    public function testParentDirSegmentIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain ".." segments');

        ChannelScope::fromInput('../other');
    }

    public function testParentDirSegmentIsRejectedInTheMiddle(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ChannelScope::fromInput('objects/../../etc');
    }

    public function testAbsolutePath(): void
    {
        self::assertSame(
            '/pub/res/objects/de',
            (new ChannelScope('objects/de'))->absolutePath('/pub/res'),
        );
        self::assertSame(
            '/pub/res',
            (new ChannelScope())->absolutePath('/pub/res/'),
        );
    }

    public function testPrefixWithinIsEmptyWhenScopeCoversWholeChannel(): void
    {
        $scope = new ChannelScope();

        self::assertSame('', $scope->prefixWithin('/pub/res', '/pub/res/objects'));
        self::assertSame('', $scope->prefixWithin('/pub/res', '/pub/res/media/public'));
    }

    public function testPrefixWithinWhenScopeEqualsRoot(): void
    {
        $scope = new ChannelScope('objects');

        self::assertSame('', $scope->prefixWithin('/pub/res', '/pub/res/objects'));
    }

    public function testPrefixWithinWhenScopeIsBelowRoot(): void
    {
        $scope = new ChannelScope('objects/de/produkte');

        self::assertSame(
            'de/produkte',
            $scope->prefixWithin('/pub/res', '/pub/res/objects'),
        );
    }

    public function testPrefixWithinWhenScopeIsAboveRoot(): void
    {
        // The scope contains the whole media tree, so nothing is filtered out.
        $scope = new ChannelScope('media');

        self::assertSame('', $scope->prefixWithin('/pub/res', '/pub/res/media/public'));
    }

    public function testPrefixWithinIsNullWhenDisjoint(): void
    {
        $scope = new ChannelScope('objects/de');

        self::assertNull($scope->prefixWithin('/pub/res', '/pub/res/media/public'));
    }

    public function testPrefixWithinIsNullForSiblingWithCommonNamePrefix(): void
    {
        // "objects-old" must not be treated as being inside "objects".
        $scope = new ChannelScope('objects-old');

        self::assertNull($scope->prefixWithin('/pub/res', '/pub/res/objects'));
    }

    public function testPrefixWithinIgnoresTrailingSlashOnRoot(): void
    {
        $scope = new ChannelScope('objects/de');

        self::assertSame('de', $scope->prefixWithin('/pub/res', '/pub/res/objects/'));
    }

    public function testPrefixWithinInDocumentRootLayout(): void
    {
        // DOCUMENT_ROOT: base, resource and media dir are the same directory.
        $scope = new ChannelScope('sitemap');

        self::assertSame('sitemap', $scope->prefixWithin('/pub/www', '/pub/www'));
    }
}
