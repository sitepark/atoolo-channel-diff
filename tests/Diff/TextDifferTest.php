<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Tests\Diff;

use Atoolo\ChannelDiff\Diff\TextDiffer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextDiffer::class)]
final class TextDifferTest extends TestCase
{
    public function testIdenticalTextsGiveAnEmptyDiff(): void
    {
        self::assertSame('', (new TextDiffer())->diff("a\nb\n", "a\nb\n"));
    }

    public function testChangedLineIsShownWithContext(): void
    {
        $diff = (new TextDiffer())->diff(
            "1\n2\n3\n4\n5\n6\n7\n",
            "1\n2\n3\nX\n5\n6\n7\n",
        );

        self::assertSame(
            "@@ -2,5 +2,5 @@\n 2\n 3\n-4\n+X\n 5\n 6",
            $diff,
        );
    }

    public function testAddedAndRemovedLines(): void
    {
        $diff = (new TextDiffer())->diff("a\nb\nc\n", "a\nc\nd\n");

        self::assertSame("@@ -1,3 +1,3 @@\n a\n-b\n c\n+d", $diff);
    }

    public function testEmptyAgainstContent(): void
    {
        self::assertSame("@@ -1,0 +1,2 @@\n+x\n+y", (new TextDiffer())->diff('', "x\ny\n"));
    }

    public function testDistantChangesFormSeparateHunks(): void
    {
        $a = implode("\n", range(1, 20)) . "\n";
        $b = str_replace(["\n2\n", "\n19\n"], ["\nA\n", "\nB\n"], $a);

        $diff = (new TextDiffer())->diff($a, $b);

        self::assertSame(2, substr_count($diff, '@@ -'));
        self::assertStringContainsString("-2\n+A", $diff);
        self::assertStringContainsString("-19\n+B", $diff);
    }

    public function testOnlyTrailingNewlineDiffers(): void
    {
        self::assertSame('@@ trailing newline differs @@', (new TextDiffer())->diff("a\n", 'a'));
    }

    public function testLongOutputIsCapped(): void
    {
        $a = implode("\n", range(1, 300)) . "\n";
        $b = implode("\n", range(301, 600)) . "\n";

        $lines = explode("\n", (new TextDiffer())->diff($a, $b));

        self::assertCount(101, $lines);
        self::assertStringStartsWith('... (', $lines[100]);
    }

    public function testHugeMiddlePartFallsBackToWholesaleReplacement(): void
    {
        $a = implode("\n", range(1, 2100)) . "\n";
        $b = implode("\n", range(5001, 7100)) . "\n";

        $diff = (new TextDiffer())->diff($a, $b);

        self::assertStringStartsWith('@@ -1,2100 +1,2100 @@', $diff);
        self::assertStringContainsString('... (', $diff);
    }
}
