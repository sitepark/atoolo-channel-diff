<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Report;

use Atoolo\ChannelDiff\Diff\ChangeType;
use Atoolo\ChannelDiff\Diff\DiffReport;
use Atoolo\ChannelDiff\Diff\EntryStatus;
use Atoolo\ChannelDiff\Diff\FieldDiff;
use Atoolo\ChannelDiff\Diff\MediaDiff;
use Atoolo\ChannelDiff\Diff\ResourceDiff;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renders a {@see DiffReport} as human-readable, coloured console output.
 */
final class ConsoleReportRenderer
{
    public function __construct(
        private readonly ValueFormatter $formatter = new ValueFormatter(),
    ) {}

    public function render(DiffReport $report, SymfonyStyle $io): void
    {
        $io->section('Channels');
        $lines = [
            sprintf('A: %s (%s)', $report->channelA->baseDir, $report->channelA->layout->value),
            sprintf('B: %s (%s)', $report->channelB->baseDir, $report->channelB->layout->value),
        ];
        if (!$report->scope->isAll()) {
            $lines[] = sprintf('Scope: %s', $report->scope->subPath);
        }
        foreach ($report->rules->sources as $source) {
            $lines[] = sprintf('Rules: %s', $source);
        }
        if ($report->rules->floatPrecision !== null) {
            $lines[] = sprintf(
                'Float precision: %d decimal places',
                $report->rules->floatPrecision,
            );
        }
        $io->listing($lines);

        $this->renderResources($report, $io);
        if ($report->mediaCompared) {
            $this->renderMedia($report, $io);
        }
        $this->renderSummary($report, $io);
    }

    private function renderResources(DiffReport $report, SymfonyStyle $io): void
    {
        $io->section('Resources');

        if ($report->resourceDiffs === []) {
            $io->writeln('<info>No resource differences.</info>');
            return;
        }

        foreach ($report->resourceDiffs as $diff) {
            $this->renderResourceDiff($diff, $io);
        }
    }

    private function renderResourceDiff(ResourceDiff $diff, SymfonyStyle $io): void
    {
        switch ($diff->status) {
            case EntryStatus::ONLY_IN_A:
                $io->writeln(sprintf('<fg=red>− only in A</> %s%s', $diff->key, $this->suffix($diff->label)));
                break;
            case EntryStatus::ONLY_IN_B:
                $io->writeln(sprintf('<fg=green>+ only in B</> %s%s', $diff->key, $this->suffix($diff->label)));
                break;
            case EntryStatus::READ_ERROR:
                $io->writeln(sprintf('<fg=magenta>! error</> %s — %s', $diff->key, (string) $diff->error));
                break;
            case EntryStatus::CHANGED:
                $io->writeln(sprintf('<fg=yellow>~ changed</> %s', $diff->key));
                foreach ($diff->fieldDiffs as $field) {
                    $io->writeln('    ' . $this->renderField($field));
                }
                break;
        }
    }

    private function renderField(FieldDiff $field): string
    {
        return match ($field->type) {
            ChangeType::ADDED => sprintf(
                '<fg=green>+ %s</>: %s',
                $field->path,
                $this->formatter->format($field->newValue),
            ),
            ChangeType::REMOVED => sprintf(
                '<fg=red>− %s</>: %s',
                $field->path,
                $this->formatter->format($field->oldValue),
            ),
            ChangeType::CHANGED => sprintf(
                '<fg=yellow>~ %s</>: %s <fg=gray>=></> %s',
                $field->path,
                $this->formatter->format($field->oldValue),
                $this->formatter->format($field->newValue),
            ),
        };
    }

    private function renderMedia(DiffReport $report, SymfonyStyle $io): void
    {
        $io->section('Media');

        if ($report->mediaDiffs === []) {
            $io->writeln('<info>No media differences.</info>');
            return;
        }

        foreach ($report->mediaDiffs as $diff) {
            $this->renderMediaDiff($diff, $io);
        }
    }

    private function renderMediaDiff(MediaDiff $diff, SymfonyStyle $io): void
    {
        switch ($diff->status) {
            case EntryStatus::ONLY_IN_A:
                $io->writeln(sprintf('<fg=red>− only in A</> %s', $diff->key));
                break;
            case EntryStatus::ONLY_IN_B:
                $io->writeln(sprintf('<fg=green>+ only in B</> %s', $diff->key));
                break;
            case EntryStatus::CHANGED:
                $io->writeln(sprintf(
                    '<fg=yellow>~ changed</> %s (%s … ≠ %s …)',
                    $diff->key,
                    substr((string) $diff->hashA, 0, 12),
                    substr((string) $diff->hashB, 0, 12),
                ));
                break;
            case EntryStatus::READ_ERROR:
                break;
        }
    }

    private function renderSummary(DiffReport $report, SymfonyStyle $io): void
    {
        $io->section('Summary');

        $rows = [
            ['Resources A', (string) $report->totalResourcesA],
            ['Resources B', (string) $report->totalResourcesB],
            ['Resources identical', (string) $report->resourcesIdentical],
            ['Resources differing', (string) count($report->resourceDiffs)],
        ];
        if ($report->mediaCompared) {
            $rows[] = ['Media A', (string) $report->totalMediaA];
            $rows[] = ['Media B', (string) $report->totalMediaB];
            $rows[] = ['Media identical', (string) $report->mediaIdentical];
            $rows[] = ['Media differing', (string) count($report->mediaDiffs)];
        }
        $io->table(['Metric', 'Count'], $rows);

        if ($report->hasDifferences()) {
            $io->warning('Channels differ.');
        } else {
            $io->success('Channels are identical.');
        }
    }

    private function suffix(?string $label): string
    {
        return $label === null ? '' : ' <fg=gray>(' . $label . ')</>';
    }
}
