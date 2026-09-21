<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Command;

use Atoolo\ChannelDiff\Channel\ChannelFactory;
use Atoolo\ChannelDiff\Channel\ChannelScope;
use Atoolo\ChannelDiff\Channel\PublicationChannel;
use Atoolo\ChannelDiff\Diff\ChannelDiffer;
use Atoolo\ChannelDiff\Diff\IgnoreList;
use Atoolo\ChannelDiff\Report\ConsoleReportRenderer;
use Atoolo\ChannelDiff\Report\JsonReportRenderer;
use Atoolo\ChannelDiff\Rules\RuleFileLoader;
use Atoolo\ChannelDiff\Rules\RuleFileLocator;
use Atoolo\ChannelDiff\Rules\RuleSet;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'channel:diff',
    description: 'Compare two IES publication channels.',
)]
final class DiffCommand extends Command
{
    private const FORMAT_CONSOLE = 'console';
    private const FORMAT_JSON = 'json';

    public function __construct(
        private readonly ChannelFactory $channelFactory,
        private readonly ChannelDiffer $differ,
        private readonly ConsoleReportRenderer $consoleRenderer,
        private readonly JsonReportRenderer $jsonRenderer,
        private readonly RuleFileLocator $ruleFileLocator,
        private readonly RuleFileLoader $ruleFileLoader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('channelA', InputArgument::REQUIRED, 'Base directory of the first publication channel.')
            ->addArgument('channelB', InputArgument::REQUIRED, 'Base directory of the second publication channel.')
            ->addArgument('subPath', InputArgument::OPTIONAL, 'Restrict the comparison to this sub directory, relative to the channel base directory (e.g. "objects/de" or "media/public/img").')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: console or json.', self::FORMAT_CONSOLE)
            ->addOption('ignore', 'i', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Additional dot-notation field path to ignore (repeatable).')
            ->addOption('ignore-config', null, InputOption::VALUE_REQUIRED, 'PHP file returning a list of dot-notation field paths to ignore.')
            ->addOption('exclude-resource', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Resource path to leave out of the comparison entirely, together with its media (wildcards allowed, repeatable).')
            ->addOption('rules', null, InputOption::VALUE_REQUIRED, 'Rule file (YAML) with accepted differences. By default the nearest "' . RuleFileLocator::FILE_NAMES[0] . '" at or above either channel base directory is used.')
            ->addOption('no-rules', null, InputOption::VALUE_NONE, 'Ignore any rule file found next to the channels.')
            ->addOption('float-precision', null, InputOption::VALUE_REQUIRED, 'Number of decimal places at which two floats still count as equal (overrides the rule file).')
            ->addOption('no-media', null, InputOption::VALUE_NONE, 'Skip the binary media comparison.')
            ->addOption('strict-null', null, InputOption::VALUE_NONE, 'Treat a null field and a missing field as different (by default they are equal).')
            ->addOption('strict-empty-string', null, InputOption::VALUE_NONE, 'Treat an empty-string field and a missing field as different (by default they are equal).')
            ->addOption('strict-empty-array', null, InputOption::VALUE_NONE, 'Treat an empty-array field and a missing field as different (by default they are equal).')
            ->addOption('strict-uuid-keys', null, InputOption::VALUE_NONE, 'Treat UUID array keys as significant (by default arrays keyed by UUIDs are matched by content, ignoring the volatile keys).')
            ->addOption('numeric-strings', null, InputOption::VALUE_NONE, 'Treat a numeric string and the same number as equal ("600" = 600). Off by default.')
            ->setHelp(
                'Compares two IES publication channels by matching resources and '
                . 'media on their relative file path. Resource PHP files are compared '
                . 'as nested arrays (with volatile fields ignored); binary media are '
                . 'compared by sha256 hash.' . PHP_EOL . PHP_EOL
                . 'The optional third argument restricts the comparison to a sub '
                . 'directory of both channels. It is resolved against the channel '
                . 'base directory, so it addresses the resource tree and the media '
                . 'tree alike; a sub path that lies outside one of the two trees '
                . 'simply excludes it (e.g. "objects/de" compares no media).'
                . PHP_EOL . PHP_EOL
                . 'Differences that have been reviewed and accepted can be recorded '
                . 'in a rule file so they stop being reported. Place a "'
                . RuleFileLocator::FILE_NAMES[0] . '" next to the channels (it is '
                . 'looked up from each channel base directory upwards, so one file '
                . 'above both channels covers both):' . PHP_EOL . PHP_EOL
                . '    excludes:' . PHP_EOL
                . '      - \'**.sources.*.static\'' . PHP_EOL
                . '    excludeResources:' . PHP_EOL
                . '      - \'testseiten/only-one-channel-can-build-this.php\'' . PHP_EOL
                . '    floatPrecision: 7' . PHP_EOL
                . '    numericStringsEqualNumbers: true' . PHP_EOL . PHP_EOL
                . '"excludes" uses the same field paths and wildcards as --ignore. '
                . '"floatPrecision" is the number of decimal places at which two '
                . 'floats still count as equal.' . PHP_EOL . PHP_EOL
                . '"numericStringsEqualNumbers" accepts a notation change where one '
                . 'channel writes a number as a string and the other as a number '
                . '("600" and 600). It differs from an exclude in kind: no field '
                . 'is blinded, a value that really changed is still reported. Only '
                . 'the exact decimal form of an integer counts, so "1e3" and 1000 '
                . 'remain different.' . PHP_EOL . PHP_EOL
                . '"excludeResources" is different in kind: it names whole resources '
                . 'by their slash-separated path, and a matched resource drops out of '
                . 'the comparison altogether - along with the media in its '
                . '".media" sidecar directory. Use it where one channel cannot '
                . 'produce a page at all, so there is nothing to compare rather than '
                . 'a difference to accept. The report lists each pattern with what it '
                . 'removed, and warns about one that matched nothing.' . PHP_EOL . PHP_EOL
                . 'Exit code 0 = identical, 1 = differences found, 2 = error.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $format = (string) $input->getOption('format');
        if (!in_array($format, [self::FORMAT_CONSOLE, self::FORMAT_JSON], true)) {
            $io->error(sprintf('Unknown format "%s". Use "console" or "json".', $format));
            return 2;
        }

        try {
            $channelA = $this->channelFactory->create((string) $input->getArgument('channelA'));
            $channelB = $this->channelFactory->create((string) $input->getArgument('channelB'));
            $scope = $this->buildScope($input, $channelA, $channelB);
            $rules = $this->buildRuleSet($input, $channelA, $channelB);
            $ignore = $this->buildIgnoreList($input);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return 2;
        }

        $report = $this->differ->diff(
            $channelA,
            $channelB,
            $ignore,
            $scope,
            $rules,
            !$input->getOption('no-media'),
            !$input->getOption('strict-null'),
            !$input->getOption('strict-empty-string'),
            !$input->getOption('strict-empty-array'),
            !$input->getOption('strict-uuid-keys'),
        );

        if ($format === self::FORMAT_JSON) {
            $output->writeln($this->jsonRenderer->render($report));
        } else {
            $this->consoleRenderer->render($report, $io);
        }

        return $report->hasDifferences() ? 1 : 0;
    }

    /**
     * A sub path that exists in neither channel is a typo, not an empty diff:
     * reporting "identical" for it would be actively misleading. Existing in
     * only one channel, on the other hand, is a real difference.
     */
    private function buildScope(
        InputInterface $input,
        PublicationChannel $channelA,
        PublicationChannel $channelB,
    ): ChannelScope {
        $argument = $input->getArgument('subPath');
        $scope = ChannelScope::fromInput(
            is_string($argument) ? $argument : null,
        );

        if (
            !$scope->isAll()
            && !is_dir($scope->absolutePath($channelA->baseDir))
            && !is_dir($scope->absolutePath($channelB->baseDir))
        ) {
            throw new \InvalidArgumentException(sprintf(
                'Sub path "%s" does not exist in either channel.',
                $scope->subPath,
            ));
        }

        return $scope;
    }

    /**
     * Collects the accepted differences: an explicit --rules file, or otherwise
     * the nearest rule file at or above either channel (one file above both
     * channels therefore governs both), with --float-precision on top.
     */
    private function buildRuleSet(
        InputInterface $input,
        PublicationChannel $channelA,
        PublicationChannel $channelB,
    ): RuleSet {
        $rules = new RuleSet();

        $explicit = $input->getOption('rules');
        if (is_string($explicit) && $explicit !== '') {
            $rules = $rules->merge($this->ruleFileLoader->load($explicit));
        } elseif (!$input->getOption('no-rules')) {
            $files = $this->ruleFileLocator->locateAll([
                $channelA->baseDir,
                $channelB->baseDir,
            ]);
            foreach ($files as $file) {
                $rules = $rules->merge($this->ruleFileLoader->load($file));
            }
        }

        /** @var list<string> $excludeResources */
        $excludeResources = $input->getOption('exclude-resource');
        if ($excludeResources !== []) {
            $rules = $rules->merge(new RuleSet(excludeResources: $excludeResources));
        }

        if ($input->getOption('numeric-strings') === true) {
            $rules = $rules->merge(new RuleSet(numericStringsEqualNumbers: true));
        }

        $precision = $input->getOption('float-precision');
        if (is_string($precision) && $precision !== '') {
            if (!ctype_digit($precision)) {
                throw new \InvalidArgumentException(sprintf(
                    'Float precision must be a non-negative integer, got "%s".',
                    $precision,
                ));
            }
            $rules = $rules->merge(new RuleSet(
                floatPrecision: RuleFileLoader::validatePrecision((int) $precision),
            ));
        }

        return $rules;
    }

    private function buildIgnoreList(InputInterface $input): IgnoreList
    {
        $ignore = IgnoreList::default();

        /** @var list<string> $additional */
        $additional = $input->getOption('ignore');
        if ($additional !== []) {
            $ignore = $ignore->withAdditional($additional);
        }

        $configFile = $input->getOption('ignore-config');
        if (is_string($configFile) && $configFile !== '') {
            $ignore = $ignore->withAdditional($this->loadIgnoreConfig($configFile));
        }

        return $ignore;
    }

    /**
     * @return list<string>
     */
    private function loadIgnoreConfig(string $file): array
    {
        if (!is_file($file)) {
            throw new \InvalidArgumentException(
                sprintf('Ignore config file "%s" does not exist.', $file),
            );
        }

        $paths = require $file;
        if (!is_array($paths)) {
            throw new \InvalidArgumentException(
                sprintf('Ignore config file "%s" must return an array of strings.', $file),
            );
        }

        $result = [];
        foreach ($paths as $path) {
            if (is_string($path)) {
                $result[] = $path;
            }
        }

        return $result;
    }
}
