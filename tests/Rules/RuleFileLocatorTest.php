<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Tests\Rules;

use Atoolo\ChannelDiff\Rules\RuleFileLocator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuleFileLocator::class)]
final class RuleFileLocatorTest extends TestCase
{
    private string $root;

    private RuleFileLocator $locator;

    protected function setUp(): void
    {
        $this->locator = new RuleFileLocator();

        $root = tempnam(sys_get_temp_dir(), 'channel-diff-rules');
        self::assertIsString($root);
        unlink($root);
        mkdir($root . '/www/resources/objects', 0o777, true);
        mkdir($root . '/www/resources.old');
        // The locator resolves symlinks, and the temp dir may well be one.
        $real = realpath($root);
        self::assertIsString($real);
        $this->root = $real;
    }

    protected function tearDown(): void
    {
        $this->removeRecursively($this->root);
    }

    private function removeRecursively(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeRecursively($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    private function write(string $relativePath): string
    {
        $file = $this->root . '/' . $relativePath;
        file_put_contents($file, "excludes:\n  - 'a.b'\n");

        return $file;
    }

    public function testReturnsNullWhenThereIsNoRuleFile(): void
    {
        self::assertNull($this->locator->locate($this->root . '/www/resources'));
    }

    public function testReturnsNullForANonExistentDirectory(): void
    {
        self::assertNull($this->locator->locate($this->root . '/does-not-exist'));
    }

    public function testFindsTheFileInTheStartDirectory(): void
    {
        $file = $this->write('www/resources/channel-diff.yaml');

        self::assertSame($file, $this->locator->locate($this->root . '/www/resources'));
    }

    public function testFindsTheFileInAParentDirectory(): void
    {
        $file = $this->write('www/channel-diff.yaml');

        self::assertSame($file, $this->locator->locate($this->root . '/www/resources'));
    }

    public function testWalksUpMoreThanOneLevel(): void
    {
        $file = $this->write('channel-diff.yaml');

        self::assertSame(
            $file,
            $this->locator->locate($this->root . '/www/resources/objects'),
        );
    }

    public function testTheNearestFileWins(): void
    {
        $this->write('channel-diff.yaml');
        $near = $this->write('www/resources/channel-diff.yaml');

        self::assertSame($near, $this->locator->locate($this->root . '/www/resources'));
    }

    public function testYmlExtensionIsAccepted(): void
    {
        $file = $this->write('www/channel-diff.yml');

        self::assertSame($file, $this->locator->locate($this->root . '/www/resources'));
    }

    public function testYamlWinsOverYmlInTheSameDirectory(): void
    {
        $yaml = $this->write('www/channel-diff.yaml');
        $this->write('www/channel-diff.yml');

        self::assertSame($yaml, $this->locator->locate($this->root . '/www/resources'));
    }

    public function testTwoChannelsBelowOneDirectoryShareASingleFile(): void
    {
        $file = $this->write('www/channel-diff.yaml');

        $found = $this->locator->locateAll([
            $this->root . '/www/resources',
            $this->root . '/www/resources.old',
        ]);

        self::assertSame([$file], $found);
    }

    public function testChannelsWithDifferentFilesYieldBoth(): void
    {
        $a = $this->write('www/resources/channel-diff.yaml');
        $b = $this->write('www/resources.old/channel-diff.yaml');

        $found = $this->locator->locateAll([
            $this->root . '/www/resources',
            $this->root . '/www/resources.old',
        ]);

        self::assertSame([$a, $b], $found);
    }

    public function testLocateAllSkipsDirectoriesWithoutAFile(): void
    {
        self::assertSame([], $this->locator->locateAll([$this->root . '/www/resources']));
    }
}
