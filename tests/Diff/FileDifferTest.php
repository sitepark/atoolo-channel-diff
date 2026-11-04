<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Tests\Diff;

use Atoolo\ChannelDiff\Diff\ArrayDiffer;
use Atoolo\ChannelDiff\Diff\EntryStatus;
use Atoolo\ChannelDiff\Diff\FileDiffer;
use Atoolo\ChannelDiff\Diff\IgnoreList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileDiffer::class)]
final class FileDifferTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/file-differ-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    private function write(string $name, string $content): string
    {
        $file = $this->dir . '/' . $name;
        file_put_contents($file, $content);

        return $file;
    }

    /**
     * @return \Closure(array<array-key, mixed>, array<array-key, mixed>): list<\Atoolo\ChannelDiff\Diff\FieldDiff>
     */
    private function compareArrays(): \Closure
    {
        $differ = new ArrayDiffer();

        return static fn(array $a, array $b): array => $differ->diff($a, $b, IgnoreList::default());
    }

    public function testEqualTextFilesGiveNull(): void
    {
        $a = $this->write('a.txt', "x\n");
        $b = $this->write('b.txt', "x\n");

        self::assertNull((new FileDiffer())->compare('f.txt', $a, $b, $this->compareArrays()));
    }

    public function testChangedTextFileGivesLineDiff(): void
    {
        $a = $this->write('a.txt', "x\ny\n");
        $b = $this->write('b.txt', "x\nz\n");

        $diff = (new FileDiffer())->compare('f.txt', $a, $b, $this->compareArrays());

        self::assertNotNull($diff);
        self::assertSame(EntryStatus::CHANGED, $diff->status);
        self::assertSame("@@ -1,2 +1,2 @@\n x\n-y\n+z", $diff->textDiff);
        self::assertSame([], $diff->fieldDiffs);
    }

    public function testJsonIsComparedStructurally(): void
    {
        $a = $this->write('a.json', '{"a": 1, "b": {"c": 2}}');
        $b = $this->write('b.json', "{\n \"b\": {\"c\": 3},\n \"a\": 1\n}");

        $diff = (new FileDiffer())->compare('f.json', $a, $b, $this->compareArrays());

        self::assertNotNull($diff);
        self::assertNull($diff->textDiff);
        self::assertCount(1, $diff->fieldDiffs);
        self::assertSame('b.c', $diff->fieldDiffs[0]->path);
    }

    public function testJsonDifferingOnlyInFormattingIsEqual(): void
    {
        $a = $this->write('a.json', '{"a":1,"b":[1,2]}');
        $b = $this->write('b.json', "{\n  \"b\": [1, 2],\n  \"a\": 1\n}\n");

        self::assertNull((new FileDiffer())->compare('f.json', $a, $b, $this->compareArrays()));
    }

    public function testBrokenJsonFallsBackToText(): void
    {
        $a = $this->write('a.json', '{"a": 1');
        $b = $this->write('b.json', '{"a": 2');

        $diff = (new FileDiffer())->compare('f.json', $a, $b, $this->compareArrays());

        self::assertNotNull($diff);
        self::assertSame([], $diff->fieldDiffs);
        self::assertStringContainsString('-{"a": 1', (string) $diff->textDiff);
    }

    public function testBinaryFilesAreComparedByHash(): void
    {
        $a = $this->write('a.bin', "\0\1");
        $b = $this->write('b.bin', "\0\2");
        $c = $this->write('c.bin', "\0\1");

        $differ = new FileDiffer();
        $diff = $differ->compare('f.bin', $a, $b, $this->compareArrays());

        self::assertNotNull($diff);
        self::assertStringStartsWith('binary files differ (sha256 ', (string) $diff->textDiff);
        self::assertNull($differ->compare('f.bin', $a, $c, $this->compareArrays()));
    }

    public function testHashesAreMaskedOnRequest(): void
    {
        $a = $this->write('a', "# comment\nadmin:\$apr1\$s1\$h1\nuser:\$2y\$10\$abc\n");
        $b = $this->write('b', "# comment\nadmin:\$apr1\$s2\$h2\nuser:\$2y\$10\$xyz\n");
        $differ = new FileDiffer();

        self::assertNotNull($differ->compare('.htpasswd', $a, $b, $this->compareArrays()));
        self::assertNull($differ->compare('.htpasswd', $a, $b, $this->compareArrays(), true));
    }

    public function testMaskingStillShowsAChangedUser(): void
    {
        $a = $this->write('a', "admin:\$apr1\$s1\$h1\n");
        $b = $this->write('b', "root:\$apr1\$s2\$h2\n");

        $diff = (new FileDiffer())->compare('.htpasswd', $a, $b, $this->compareArrays(), true);

        self::assertNotNull($diff);
        self::assertSame("@@ -1,1 +1,1 @@\n-admin:<hash>\n+root:<hash>", $diff->textDiff);
    }

    public function testMaskHashesLeavesLinesWithoutColonAlone(): void
    {
        self::assertSame("# c: x\nplain\nu:<hash>", FileDiffer::maskHashes("# c: x\nplain\nu:secret"));
    }
}
