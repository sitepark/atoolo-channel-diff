<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Rules;

use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads a {@see RuleSet} from a YAML rule file:
 *
 *     excludes:
 *       - '**.sources.*.static'
 *     excludeResources:
 *       - 'testseiten/only-here.php'
 *     floatPrecision: 7
 *
 * The file is parsed as data, never executed, since it lives next to the
 * publication channels rather than in this project.
 */
final class RuleFileLoader
{
    /**
     * Highest precision that still makes sense for a double.
     */
    private const MAX_FLOAT_PRECISION = 15;

    /**
     * Keys a rule file may contain. Anything else is rejected rather than
     * silently ignored, so a typo in a rule never passes as an applied rule.
     */
    private const SUPPORTED_KEYS = ['excludes', 'excludeResources', 'floatPrecision'];

    public function load(string $file): RuleSet
    {
        if (!is_file($file)) {
            throw new InvalidArgumentException(
                sprintf('Rule file "%s" does not exist.', $file),
            );
        }

        try {
            $parsed = Yaml::parseFile($file);
        } catch (ParseException $e) {
            throw new InvalidArgumentException(
                sprintf('Rule file "%s" is not valid YAML: %s', $file, $e->getMessage()),
                0,
                $e,
            );
        }

        if ($parsed === null) {
            // An empty file is a valid "no rules yet" placeholder.
            return new RuleSet(sources: [$file]);
        }
        if (!is_array($parsed)) {
            throw new InvalidArgumentException(
                sprintf('Rule file "%s" must contain a YAML mapping.', $file),
            );
        }

        $this->rejectUnknownKeys($file, $parsed);

        return new RuleSet(
            $this->readStringList($file, $parsed, 'excludes'),
            $this->readStringList($file, $parsed, 'excludeResources'),
            $this->readFloatPrecision($file, $parsed),
            [$file],
        );
    }

    /**
     * @param array<array-key, mixed> $parsed
     */
    private function rejectUnknownKeys(string $file, array $parsed): void
    {
        $unknown = array_diff(
            array_keys($parsed),
            self::SUPPORTED_KEYS,
        );
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Rule file "%s" contains unknown key(s): %s. Supported keys: %s.',
                $file,
                implode(', ', array_map(static fn(mixed $k): string => (string) $k, $unknown)),
                implode(', ', self::SUPPORTED_KEYS),
            ));
        }
    }

    /**
     * @param array<array-key, mixed> $parsed
     * @return list<string>
     */
    private function readStringList(string $file, array $parsed, string $key): array
    {
        // "??" also covers an explicitly empty key, which parses to null.
        $entries = $parsed[$key] ?? [];
        if (!is_array($entries)) {
            throw new InvalidArgumentException(
                sprintf('Rule file "%s": "%s" must be a list of paths.', $file, $key),
            );
        }

        $result = [];
        foreach ($entries as $entry) {
            if (!is_string($entry)) {
                throw new InvalidArgumentException(sprintf(
                    'Rule file "%s": every entry of "%s" must be a string, got %s.',
                    $file,
                    $key,
                    get_debug_type($entry),
                ));
            }
            $entry = trim($entry);
            if ($entry !== '') {
                $result[] = $entry;
            }
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $parsed
     */
    private function readFloatPrecision(string $file, array $parsed): ?int
    {
        $precision = $parsed['floatPrecision'] ?? null;
        if ($precision === null) {
            return null;
        }
        if (!is_int($precision)) {
            throw new InvalidArgumentException(sprintf(
                'Rule file "%s": "floatPrecision" must be an integer, got %s.',
                $file,
                get_debug_type($precision),
            ));
        }

        return self::validatePrecision($precision);
    }

    /**
     * @throws InvalidArgumentException if the precision is out of range
     */
    public static function validatePrecision(int $precision): int
    {
        if ($precision < 0 || $precision > self::MAX_FLOAT_PRECISION) {
            throw new InvalidArgumentException(sprintf(
                'Float precision must be between 0 and %d, got %d.',
                self::MAX_FLOAT_PRECISION,
                $precision,
            ));
        }

        return $precision;
    }
}
