<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Report;

use Atoolo\ChannelDiff\Diff\ChangeType;
use Atoolo\ChannelDiff\Diff\DiffReport;
use Atoolo\ChannelDiff\Diff\ExclusionStat;
use Atoolo\ChannelDiff\Diff\FieldDiff;
use Atoolo\ChannelDiff\Diff\MediaDiff;
use Atoolo\ChannelDiff\Diff\ResourceDiff;

/**
 * Renders a {@see DiffReport} as a machine-readable JSON document.
 */
final class JsonReportRenderer
{
    public function __construct(
        private readonly ValueFormatter $formatter = new ValueFormatter(),
    ) {}

    public function render(DiffReport $report): string
    {
        $data = [
            'channelA' => $this->channel($report->channelA),
            'channelB' => $this->channel($report->channelB),
            'scope' => $report->scope->isAll() ? null : $report->scope->subPath,
            'rules' => [
                'sources' => $report->rules->sources,
                'excludes' => $report->rules->excludes,
                'excludeResources' => array_map(
                    $this->exclusion(...),
                    $report->exclusionStats,
                ),
                'floatPrecision' => $report->rules->floatPrecision,
            ],
            'stats' => [
                'resources' => [
                    'totalA' => $report->totalResourcesA,
                    'totalB' => $report->totalResourcesB,
                    'identical' => $report->resourcesIdentical,
                    'differing' => count($report->resourceDiffs),
                    'excluded' => $report->resourcesExcluded,
                ],
                'media' => [
                    'compared' => $report->mediaCompared,
                    'totalA' => $report->totalMediaA,
                    'totalB' => $report->totalMediaB,
                    'identical' => $report->mediaIdentical,
                    'differing' => count($report->mediaDiffs),
                    'excluded' => $report->mediaExcluded,
                ],
            ],
            'resources' => array_map($this->resource(...), $report->resourceDiffs),
            'media' => array_map($this->media(...), $report->mediaDiffs),
            'hasDifferences' => $report->hasDifferences(),
        ];

        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return $json !== false ? $json : '{}';
    }

    /**
     * @return array<string, string>
     */
    private function channel(\Atoolo\ChannelDiff\Channel\PublicationChannel $channel): array
    {
        return [
            'baseDir' => $channel->baseDir,
            'resourceDir' => $channel->resourceDir,
            'mediaDir' => $channel->mediaDir,
            'layout' => $channel->layout->value,
            'locale' => $channel->locale,
            'resourcePathType' => $channel->resourcePathType,
        ];
    }

    /**
     * A pattern plus what it removed, so a consumer can spot a rule that no
     * longer matches anything without re-running the comparison.
     *
     * @return array<string, mixed>
     */
    private function exclusion(ExclusionStat $stat): array
    {
        return [
            'pattern' => $stat->pattern,
            'resources' => $stat->resources,
            'media' => $stat->media,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(ResourceDiff $diff): array
    {
        return [
            'key' => $diff->key,
            'status' => $diff->status->value,
            'label' => $diff->label,
            'error' => $diff->error,
            'fields' => array_map($this->field(...), $diff->fieldDiffs),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function field(FieldDiff $field): array
    {
        // Values are emitted as bounded, single-line strings (like the console
        // renderer). Full structured serialization is intentionally avoided:
        // resource values can be very large subtrees or share/cycle object
        // references, so encoding thousands of them verbatim is impractical.
        return [
            'path' => $field->path,
            'type' => $field->type->value,
            'old' => $field->type === ChangeType::ADDED
                ? null
                : $this->formatter->format($field->oldValue),
            'new' => $field->type === ChangeType::REMOVED
                ? null
                : $this->formatter->format($field->newValue),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function media(MediaDiff $diff): array
    {
        return [
            'key' => $diff->key,
            'status' => $diff->status->value,
            'hashA' => $diff->hashA,
            'hashB' => $diff->hashB,
        ];
    }
}
