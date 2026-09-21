<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Tests\Rules;

use Atoolo\ChannelDiff\Rules\RuleFileLoader;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuleFileLoader::class)]
final class RuleFileLoaderTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../resources/rules';

    private RuleFileLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new RuleFileLoader();
    }

    private function file(string $name): string
    {
        return self::FIXTURES . '/' . $name;
    }

    public function testLoadsExcludesAndFloatPrecision(): void
    {
        $rules = $this->loader->load($this->file('valid.yaml'));

        self::assertSame(['**.sources.*.static', 'searchindexdata'], $rules->excludes);
        self::assertSame(7, $rules->floatPrecision);
        self::assertSame([$this->file('valid.yaml')], $rules->sources);
    }

    public function testFloatPrecisionMayBeOmitted(): void
    {
        $rules = $this->loader->load($this->file('excludes-only.yaml'));

        self::assertSame(['a.b'], $rules->excludes);
        self::assertNull($rules->floatPrecision);
    }

    public function testAnEmptyFileIsAValidPlaceholder(): void
    {
        $rules = $this->loader->load($this->file('empty.yaml'));

        self::assertTrue($rules->isEmpty());
        self::assertSame([$this->file('empty.yaml')], $rules->sources);
    }

    public function testMissingFileIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        $this->loader->load($this->file('nope.yaml'));
    }

    public function testInvalidYamlIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not valid YAML');

        $this->loader->load($this->file('invalid-syntax.yaml'));
    }

    public function testNonMappingContentIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must contain a YAML mapping');

        $this->loader->load($this->file('scalar.yaml'));
    }

    /**
     * A typo in a key would otherwise silently drop the whole rule.
     */
    public function testUnknownKeyIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown key(s): exclude');

        $this->loader->load($this->file('unknown-key.yaml'));
    }

    public function testExcludesMustBeAList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"excludes" must be a list');

        $this->loader->load($this->file('excludes-not-a-list.yaml'));
    }

    public function testExcludeEntriesMustBeStrings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a string, got int');

        $this->loader->load($this->file('excludes-non-string.yaml'));
    }

    public function testLoadsResourceExclusions(): void
    {
        $rules = $this->loader->load($this->file('exclude-resources.yaml'));

        self::assertSame(['base.title'], $rules->excludes);
        self::assertSame(
            ['testseiten/only-in-one-channel.php', 'generated/**'],
            $rules->excludeResources,
        );
    }

    public function testResourceExclusionsMayBeOmitted(): void
    {
        $rules = $this->loader->load($this->file('valid.yaml'));

        self::assertSame([], $rules->excludeResources);
    }

    public function testResourceExclusionEntriesMustBeStrings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('every entry of "excludeResources" must be a string, got int');

        $this->loader->load($this->file('exclude-resources-non-string.yaml'));
    }

    public function testTheErrorForAnUnknownKeyNamesEverySupportedKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'excludes, excludeResources, floatPrecision, numericStringsEqualNumbers',
        );

        $this->loader->load($this->file('unknown-key.yaml'));
    }

    public function testLoadsNumericStringsEqualNumbers(): void
    {
        $rules = $this->loader->load($this->file('numeric-strings.yaml'));

        self::assertTrue($rules->numericStringsEqualNumbers);
    }

    public function testNumericStringsEqualNumbersDefaultsToFalse(): void
    {
        $rules = $this->loader->load($this->file('valid.yaml'));

        self::assertFalse($rules->numericStringsEqualNumbers);
    }

    public function testNumericStringsEqualNumbersMustBeABool(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            '"numericStringsEqualNumbers" must be true or false, got string',
        );

        $this->loader->load($this->file('numeric-strings-not-bool.yaml'));
    }

    public function testFloatPrecisionMustBeAnInteger(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"floatPrecision" must be an integer, got string');

        $this->loader->load($this->file('precision-not-int.yaml'));
    }

    public function testFloatPrecisionMustBeInRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be between 0 and 15');

        $this->loader->load($this->file('precision-out-of-range.yaml'));
    }

    public function testValidatePrecisionAcceptsTheBounds(): void
    {
        self::assertSame(0, RuleFileLoader::validatePrecision(0));
        self::assertSame(15, RuleFileLoader::validatePrecision(15));
    }

    public function testValidatePrecisionRejectsNegativeValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RuleFileLoader::validatePrecision(-1);
    }
}
