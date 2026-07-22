<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Tests\Diff;

use Atoolo\ChannelDiff\Channel\ChannelFactory;
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

    private function buildReport(bool $includeMedia = true): DiffReport
    {
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
            $includeMedia,
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
