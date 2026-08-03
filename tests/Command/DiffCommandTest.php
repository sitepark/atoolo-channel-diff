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
use Atoolo\ChannelDiff\Rules\RuleFileLoader;
use Atoolo\ChannelDiff\Rules\RuleFileLocator;
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
            new RuleFileLocator(),
            new RuleFileLoader(),
        ));
    }

    /**
     * Rule-file discovery walks up to the filesystem root, so it is switched off
     * by default here; the rule tests pass their own file explicitly.
     *
     * @param array<string, string|bool> $extra
     */
    private function diff(?string $subPath, array $extra = []): CommandTester
    {
        $tester = $this->tester();
        $input = [
            'channelA' => self::FIXTURES . '/channelA',
            'channelB' => self::FIXTURES . '/channelB',
            '--no-rules' => true,
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
            '--no-rules' => true,
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

    public function testExplicitRuleFileSuppressesTheExcludedField(): void
    {
        $tester = $this->diff(null, [
            '--rules' => self::FIXTURES . '/rules/channel-fixtures.yaml',
        ]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('channel-fixtures.yaml', $display);
        self::assertStringNotContainsString('base.title', $display);
        // Fields the rule file does not cover are still reported.
        self::assertStringContainsString('base.addedField', $display);
    }

    /**
     * The float in the fixtures differs by ~6.1e-9, so 8 decimal places are not
     * enough to make the two sides equal, but 7 are.
     */
    public function testFloatPrecisionFromTheRuleFileIsApplied(): void
    {
        $tester = $this->diff(null, [
            '--rules' => self::FIXTURES . '/rules/channel-fixtures.yaml',
        ]);

        self::assertStringContainsString('Float precision: 7', $tester->getDisplay());
        self::assertStringNotContainsString('base.focalpoint.x', $tester->getDisplay());
    }

    public function testFloatPrecisionOnTheCommandLineOverridesTheRuleFile(): void
    {
        $tester = $this->diff(null, [
            '--rules' => self::FIXTURES . '/rules/channel-fixtures.yaml',
            '--float-precision' => '8',
        ]);

        self::assertStringContainsString('Float precision: 8', $tester->getDisplay());
        self::assertStringContainsString('base.focalpoint.x', $tester->getDisplay());
    }

    public function testFloatPrecisionWithoutARuleFile(): void
    {
        $tester = $this->diff(null, ['--float-precision' => '7']);

        self::assertStringNotContainsString('base.focalpoint.x', $tester->getDisplay());
    }

    public function testNonNumericFloatPrecisionIsAnError(): void
    {
        $tester = $this->diff(null, ['--float-precision' => 'seven']);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('non-negative integer', $tester->getDisplay());
    }

    public function testMissingRuleFileIsAnError(): void
    {
        $tester = $this->diff(null, ['--rules' => self::FIXTURES . '/rules/nope.yaml']);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('does not exist', $tester->getDisplay());
    }

    public function testRuleFileIsDiscoveredAboveBothChannels(): void
    {
        // One file in the directory that holds both fixture channels.
        $discovered = self::FIXTURES . '/channel-diff.yaml';
        copy(self::FIXTURES . '/rules/channel-fixtures.yaml', $discovered);

        try {
            $tester = $this->tester();
            $tester->execute([
                'channelA' => self::FIXTURES . '/channelA',
                'channelB' => self::FIXTURES . '/channelB',
            ]);
        } finally {
            unlink($discovered);
        }

        $display = $tester->getDisplay();
        self::assertStringContainsString('channel-diff.yaml', $display);
        self::assertStringNotContainsString('base.title', $display);
        self::assertStringNotContainsString('base.focalpoint.x', $display);
    }

    public function testNoRulesIgnoresADiscoveredRuleFile(): void
    {
        $discovered = self::FIXTURES . '/channel-diff.yaml';
        copy(self::FIXTURES . '/rules/channel-fixtures.yaml', $discovered);

        try {
            $tester = $this->diff(null);
        } finally {
            unlink($discovered);
        }

        self::assertStringNotContainsString('Rules:', $tester->getDisplay());
        self::assertStringContainsString('base.title', $tester->getDisplay());
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
