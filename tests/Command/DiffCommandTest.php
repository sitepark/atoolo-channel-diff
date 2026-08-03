<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Tests\Command;

use Atoolo\ChannelDiff\Channel\ChannelFactory;
use Atoolo\ChannelDiff\Command\DiffCommand;
use Atoolo\ChannelDiff\Diff\ArrayDiffer;
use Atoolo\ChannelDiff\Diff\ChannelDiffer;
use Atoolo\ChannelDiff\Enumerator\ResourceEnumerator;
use Atoolo\ChannelDiff\Loader\ResourceFileReader;
use Atoolo\ChannelDiff\Report\ConsoleReportRenderer;
use Atoolo\ChannelDiff\Report\JsonReportRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DiffCommand::class)]
final class DiffCommandTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../resources';

    private function tester(): CommandTester
    {
        return new CommandTester(new DiffCommand(
            new ChannelFactory(),
            new ChannelDiffer(
                new ResourceEnumerator(),
                new ResourceFileReader(),
                new ArrayDiffer(),
            ),
            new ConsoleReportRenderer(),
            new JsonReportRenderer(),
        ));
    }

    /**
     * @param array<string, string|bool> $extra
     */
    private function diff(?string $subPath, array $extra = []): CommandTester
    {
        $tester = $this->tester();
        $input = [
            'channelA' => self::FIXTURES . '/channelA',
            'channelB' => self::FIXTURES . '/channelB',
        ];
        if ($subPath !== null) {
            $input['subPath'] = $subPath;
        }
        $tester->execute($extra + $input);

        return $tester;
    }

    public function testWithoutSubPathTheWholeChannelIsCompared(): void
    {
        $tester = $this->diff(null);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringNotContainsString('Scope:', $tester->getDisplay());
    }

    public function testSubPathIsShownInTheConsoleReport(): void
    {
        $tester = $this->diff('objects');

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Scope: objects', $tester->getDisplay());
    }

    public function testSubPathIsShownInTheJsonReport(): void
    {
        $tester = $this->diff('objects', ['--format' => 'json']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('"scope": "objects"', $tester->getDisplay());
    }

    public function testSubPathWithoutDifferencesExitsWithZero(): void
    {
        $tester = $this->tester();
        $tester->execute([
            'channelA' => self::FIXTURES . '/channelA',
            'channelB' => self::FIXTURES . '/channelA',
            'subPath' => 'objects',
        ]);

        self::assertSame(0, $tester->getStatusCode());
    }

    public function testUnknownSubPathIsAnError(): void
    {
        // Silently reporting "identical" for a typo would be misleading.
        $tester = $this->diff('objects/unknown');

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString(
            'does not exist in either channel',
            $tester->getDisplay(),
        );
    }

    public function testSubPathEscapingTheChannelIsAnError(): void
    {
        $tester = $this->diff('../channelB');

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('must not contain ".."', $tester->getDisplay());
    }

    public function testSubPathPresentInOnlyOneChannelIsNotAnError(): void
    {
        // Git does not track empty directories, so create it for this test only.
        $onlyInA = self::FIXTURES . '/channelA/objects/scope-only-in-a';
        mkdir($onlyInA);

        try {
            $tester = $this->diff('objects/scope-only-in-a');
        } finally {
            rmdir($onlyInA);
        }

        // Existing in one channel is a real (here: empty) comparison, not a typo.
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringNotContainsString('does not exist', $tester->getDisplay());
    }
}
