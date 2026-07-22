<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Command;

use Atoolo\ChannelDiff\Channel\ChannelFactory;
use Atoolo\ChannelDiff\Diff\ChannelDiffer;
use Atoolo\ChannelDiff\Diff\IgnoreList;
use Atoolo\ChannelDiff\Report\ConsoleReportRenderer;
use Atoolo\ChannelDiff\Report\JsonReportRenderer;
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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('channelA', InputArgument::REQUIRED, 'Base directory of the first publication channel.')
            ->addArgument('channelB', InputArgument::REQUIRED, 'Base directory of the second publication channel.')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: console or json.', self::FORMAT_CONSOLE)
            ->addOption('ignore', 'i', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Additional dot-notation field path to ignore (repeatable).')
            ->addOption('ignore-config', null, InputOption::VALUE_REQUIRED, 'PHP file returning a list of dot-notation field paths to ignore.')
            ->addOption('no-media', null, InputOption::VALUE_NONE, 'Skip the binary media comparison.')
            ->addOption('strict-null', null, InputOption::VALUE_NONE, 'Treat a null field and a missing field as different (by default they are equal).')
            ->addOption('strict-empty-string', null, InputOption::VALUE_NONE, 'Treat an empty-string field and a missing field as different (by default they are equal).')
            ->addOption('strict-empty-array', null, InputOption::VALUE_NONE, 'Treat an empty-array field and a missing field as different (by default they are equal).')
            ->addOption('strict-uuid-keys', null, InputOption::VALUE_NONE, 'Treat UUID array keys as significant (by default arrays keyed by UUIDs are matched by content, ignoring the volatile keys).')
            ->setHelp(
                'Compares two IES publication channels by matching resources and '
                . 'media on their relative file path. Resource PHP files are compared '
                . 'as nested arrays (with volatile fields ignored); binary media are '
                . 'compared by sha256 hash.' . PHP_EOL . PHP_EOL
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
            $ignore = $this->buildIgnoreList($input);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return 2;
        }

        $report = $this->differ->diff(
            $channelA,
            $channelB,
            $ignore,
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
