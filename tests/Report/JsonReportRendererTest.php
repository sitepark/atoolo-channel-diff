<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Tests\Report;

use Atoolo\ChannelDiff\Channel\ChannelLayout;
use Atoolo\ChannelDiff\Channel\PublicationChannel;
use Atoolo\ChannelDiff\Diff\ChangeType;
use Atoolo\ChannelDiff\Diff\DiffReport;
use Atoolo\ChannelDiff\Diff\EntryStatus;
use Atoolo\ChannelDiff\Diff\FieldDiff;
use Atoolo\ChannelDiff\Diff\ResourceDiff;
use Atoolo\ChannelDiff\Report\JsonReportRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonReportRenderer::class)]
final class JsonReportRendererTest extends TestCase
{
    public function testRendersValidJsonAndSanitizesClosures(): void
    {
        $channel = new PublicationChannel(
            '/a',
            '/a/objects',
            '/a/media/public',
            ChannelLayout::RESOURCE,
            'de_DE',
            '',
            'A',
        );

        $report = new DiffReport(
            $channel,
            $channel,
            [
                new ResourceDiff(
                    'page.php',
                    EntryStatus::CHANGED,
                    fieldDiffs: [
                        new FieldDiff('base.title', ChangeType::CHANGED, 'Alt', 'Neu'),
                        new FieldDiff('render', ChangeType::REMOVED, static fn(): string => 'x', null),
                    ],
                ),
            ],
            [],
            1,
            1,
            0,
            0,
            0,
            0,
            true,
        );

        $json = (new JsonReportRenderer())->render($report);
        $decoded = json_decode($json, true);

        self::assertIsArray($decoded);
        self::assertTrue($decoded['hasDifferences']);
        self::assertSame('page.php', $decoded['resources'][0]['key']);
        self::assertSame('base.title', $decoded['resources'][0]['fields'][0]['path']);
        self::assertSame('Neu', $decoded['resources'][0]['fields'][0]['new']);
        self::assertSame('<closure>', $decoded['resources'][0]['fields'][1]['old']);
    }
}
