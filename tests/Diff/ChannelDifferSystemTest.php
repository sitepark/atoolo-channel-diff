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
use Atoolo\ChannelDiff\Diff\SystemSection;
use Atoolo\ChannelDiff\Enumerator\ResourceEnumerator;
use Atoolo\ChannelDiff\Loader\ResourceFileReader;
use Atoolo\ChannelDiff\Rules\RuleSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelDiffer::class)]
#[CoversClass(ResourceEnumerator::class)]
#[CoversClass(SystemSection::class)]
final class ChannelDifferSystemTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../resources';

    private function report(
        ?string $subPath = null,
        ?RuleSet $rules = null,
        string $a = 'systemA',
        string $b = 'systemB',
    ): DiffReport {
        $factory = new ChannelFactory();
        $differ = new ChannelDiffer(
            new ResourceEnumerator(),
            new ResourceFileReader(),
            new ArrayDiffer(),
        );

        return $differ->diff(
            $factory->create(self::FIXTURES . '/' . $a),
            $factory->create(self::FIXTURES . '/' . $b),
            IgnoreList::default(),
            scope: ChannelScope::fromInput($subPath),
            rules: $rules ?? new RuleSet(),
        );
    }

    private function section(DiffReport $report, string $name): SystemSection
    {
        foreach ($report->systemSections as $section) {
            if ($section->name === $name) {
                return $section;
            }
        }
        self::fail('No section ' . $name);
    }

    /**
     * @return array<string, ResourceDiff>
     */
    private function byKey(SystemSection $section): array
    {
        $byKey = [];
        foreach ($section->diffs as $diff) {
            $byKey[$diff->key] = $diff;
        }

        return $byKey;
    }

    public function testResourcesAreUnaffectedBySystemFiles(): void
    {
        $report = $this->report();

        self::assertSame([], $report->resourceDiffs);
        self::assertSame(1, $report->resourcesIdentical);
        self::assertSame(1, $report->totalResourcesA);
    }

    public function testConfigsAreDiffedFieldByField(): void
    {
        $section = $this->section($this->report(), 'Configs');
        $byKey = $this->byKey($section);

        self::assertSame(1, $section->totalA);
        self::assertSame(2, $section->totalB);
        self::assertSame(EntryStatus::ONLY_IN_B, $byKey['configs/email/only-b.php']->status);

        $changed = $byKey['configs/indexer/genai.php'];
        self::assertSame(EntryStatus::CHANGED, $changed->status);
        // "version" is ignored by the default rules, "model" is not.
        self::assertCount(1, $changed->fieldDiffs);
        self::assertSame('model', $changed->fieldDiffs[0]->path);
    }

    public function testListShapedRedirectsAreDiffed(): void
    {
        $section = $this->section($this->report(), 'Redirects');
        $diff = $this->byKey($section)['redirects/aliases.php'];

        self::assertSame(EntryStatus::CHANGED, $diff->status);
        self::assertCount(1, $diff->fieldDiffs);
        self::assertSame('1.replacement', $diff->fieldDiffs[0]->path);
    }

    public function testNonPhpFilesAreComparedByContent(): void
    {
        $section = $this->section($this->report(), 'Files');
        $byKey = $this->byKey($section);

        self::assertSame(
            [
                'objects/.htpasswd',
                'objects/languagePackage.json',
                'objects/logo.bin',
                'objects/redirects.map',
                'objects/shortUrls',
            ],
            array_keys($byKey),
            'the vim swap file is not a channel file',
        );
        self::assertSame(EntryStatus::ONLY_IN_A, $byKey['objects/redirects.map']->status);
        self::assertStringContainsString("-four\n+FOUR", (string) $byKey['objects/shortUrls']->textDiff);
        self::assertStringContainsString('binary files differ', (string) $byKey['objects/logo.bin']->textDiff);

        $json = $byKey['objects/languagePackage.json'];
        self::assertNull($json->textDiff);
        self::assertSame(['de.hello'], array_map(static fn($f) => $f->path, $json->fieldDiffs));
    }

    public function testHtpasswdHashesCanBeMasked(): void
    {
        $plain = $this->byKey($this->section($this->report(), 'Files'));
        self::assertArrayHasKey('objects/.htpasswd', $plain);

        $masked = $this->report(rules: new RuleSet(maskHashes: ['**/.htpasswd']));
        $section = $this->section($masked, 'Files');

        self::assertArrayNotHasKey('objects/.htpasswd', $this->byKey($section));
        self::assertSame(5, $section->totalA);
        self::assertSame(1, $section->identical);
    }

    public function testExcludeResourcesApplyToSystemFiles(): void
    {
        $report = $this->report(rules: new RuleSet(excludeResources: ['configs/**', 'objects/logo.bin']));

        self::assertArrayNotHasKey('configs/indexer/genai.php', $this->byKey($this->section($report, 'Configs')));
        self::assertArrayNotHasKey('objects/logo.bin', $this->byKey($this->section($report, 'Files')));
        self::assertSame(3, $report->resourcesExcluded);
    }

    public function testScopeRestrictsSystemSections(): void
    {
        $report = $this->report('configs/indexer');

        self::assertCount(1, $report->systemSections);
        self::assertSame('Configs', $report->systemSections[0]->name);
        self::assertSame(['configs/indexer/genai.php'], array_keys($this->byKey($report->systemSections[0])));
    }

    public function testObjectsScopeKeepsFilesOnly(): void
    {
        $report = $this->report('objects');

        self::assertSame(['Files'], array_map(static fn($s) => $s->name, $report->systemSections));
    }

    public function testChannelsWithoutSystemFilesHaveNoSections(): void
    {
        $report = $this->report(a: 'channelA', b: 'channelB');

        self::assertSame([], $report->systemSections);
    }

    public function testSystemDifferencesCountAsDifferences(): void
    {
        self::assertTrue($this->report()->hasDifferences());
        self::assertFalse($this->report(a: 'systemA', b: 'systemA')->hasDifferences());
    }
}
