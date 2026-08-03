<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Tests\Diff;

use Atoolo\ChannelDiff\Channel\ChannelFactory;
use Atoolo\ChannelDiff\Channel\ChannelScope;
use Atoolo\ChannelDiff\Diff\ArrayDiffer;
use Atoolo\ChannelDiff\Diff\ChannelDiffer;
use Atoolo\ChannelDiff\Diff\DiffReport;
use Atoolo\ChannelDiff\Diff\EntryStatus;
use Atoolo\ChannelDiff\Diff\IgnoreList;
use Atoolo\ChannelDiff\Diff\ResourceDiff;
use Atoolo\ChannelDiff\Enumerator\ResourceEnumerator;
use Atoolo\ChannelDiff\Loader\ResourceFileReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelDiffer::class)]
final class ChannelDifferTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../resources';

    private function buildReport(
        bool $includeMedia = true,
        ?string $subPath = null,
    ): DiffReport {
        $factory = new ChannelFactory();
        $differ = new ChannelDiffer(
            new ResourceEnumerator(),
            new ResourceFileReader(),
            new ArrayDiffer(),
        );

        return $differ->diff(
            $factory->create(self::FIXTURES . '/channelA'),
            $factory->create(self::FIXTURES . '/channelB'),
            IgnoreList::default(),
            scope: ChannelScope::fromInput($subPath),
            includeMedia: $includeMedia,
        );
    }

    public function testResourceCountsAndStatuses(): void
    {
        $report = $this->buildReport();

        self::assertSame(3, $report->totalResourcesA);
        self::assertSame(3, $report->totalResourcesB);
        // index.php differs only in the ignored "version" field -> identical.
        self::assertSame(1, $report->resourcesIdentical);
        self::assertCount(3, $report->resourceDiffs);

        $byKey = [];
        foreach ($report->resourceDiffs as $diff) {
            $byKey[$diff->key] = $diff;
        }

        self::assertSame(EntryStatus::ONLY_IN_A, $byKey['only-a.php']->status);
        self::assertSame(EntryStatus::ONLY_IN_B, $byKey['only-b.php']->status);
        self::assertStringContainsString('id=3', (string) $byKey['only-a.php']->label);

        $page = $byKey['page.php'];
        self::assertSame(EntryStatus::CHANGED, $page->status);
        self::assertSame(
            ['base.addedField', 'base.removedField', 'base.title'],
            array_map(static fn($f) => $f->path, $page->fieldDiffs),
        );
    }

    public function testMediaHashComparison(): void
    {
        $report = $this->buildReport();

        self::assertSame(3, $report->totalMediaA);
        self::assertSame(2, $report->totalMediaB);
        self::assertSame(1, $report->mediaIdentical);
        self::assertCount(2, $report->mediaDiffs);

        $byKey = [];
        foreach ($report->mediaDiffs as $diff) {
            $byKey[$diff->key] = $diff;
        }
        self::assertSame(EntryStatus::CHANGED, $byKey['diff.bin']->status);
        self::assertSame(EntryStatus::ONLY_IN_A, $byKey['only-a.bin']->status);
        self::assertNotSame($byKey['diff.bin']->hashA, $byKey['diff.bin']->hashB);
    }

    public function testMediaCanBeSkipped(): void
    {
        $report = $this->buildReport(includeMedia: false);

        self::assertFalse($report->mediaCompared);
        self::assertSame([], $report->mediaDiffs);
        self::assertTrue($report->hasDifferences());
    }

    public function testSubPathLimitedToResourceTreeExcludesMedia(): void
    {
        $report = $this->buildReport(subPath: 'objects');

        // The resource tree is fully covered, so the counts stay the same ...
        self::assertSame(3, $report->totalResourcesA);
        self::assertSame(3, $report->totalResourcesB);
        self::assertSame(1, $report->resourcesIdentical);
        self::assertCount(3, $report->resourceDiffs);

        // ... while the media tree lies outside the scope.
        self::assertSame(0, $report->totalMediaA);
        self::assertSame(0, $report->totalMediaB);
        self::assertSame([], $report->mediaDiffs);
    }

    public function testSubPathLimitedToMediaTreeExcludesResources(): void
    {
        $report = $this->buildReport(subPath: 'media/public');

        self::assertSame(0, $report->totalResourcesA);
        self::assertSame(0, $report->totalResourcesB);
        self::assertSame([], $report->resourceDiffs);

        self::assertSame(3, $report->totalMediaA);
        self::assertSame(2, $report->totalMediaB);
        self::assertSame(1, $report->mediaIdentical);
        self::assertCount(2, $report->mediaDiffs);
    }

    public function testSubPathIsReportedInTheReport(): void
    {
        self::assertSame('objects', $this->buildReport(subPath: 'objects')->scope->subPath);
        self::assertTrue($this->buildReport()->scope->isAll());
    }

    public function testUnknownSubPathYieldsNoEntries(): void
    {
        // Rejecting a sub path that exists in neither channel is the command's
        // job; the differ just compares two empty trees.
        $report = $this->buildReport(subPath: 'objects/unknown');

        self::assertSame(0, $report->totalResourcesA);
        self::assertSame(0, $report->totalResourcesB);
        self::assertSame(0, $report->totalMediaA);
        self::assertFalse($report->hasDifferences());
    }

    public function testIdenticalChannelHasNoDifferences(): void
    {
        $factory = new ChannelFactory();
        $differ = new ChannelDiffer(
            new ResourceEnumerator(),
            new ResourceFileReader(),
            new ArrayDiffer(),
        );
        $channel = $factory->create(self::FIXTURES . '/channelA');

        $report = $differ->diff($channel, $channel, IgnoreList::default());

        self::assertFalse($report->hasDifferences());
        self::assertSame($report->totalResourcesA, $report->resourcesIdentical);
    }
}
