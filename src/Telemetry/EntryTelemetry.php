<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Telemetry;

use Cbox\StatamicTelemetry\Audit\AuditLog;

/**
 * The per-entry telemetry shown inline in the entry editor (the `telemetry`
 * fieldtype): how often that specific entry was requested and how slow, from
 * traces filtered by its `statamic.entry.id`, plus its change history from the
 * audit log. Guarded — the readers return empty when the backend is down.
 */
final class EntryTelemetry
{
    /** A week, the default look-back for the editor snapshot. */
    private const WINDOW = 604800;

    public function __construct(
        private readonly Traces $traces,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(string $entryId, int $windowSeconds = self::WINDOW): array
    {
        $traces = $this->traces->byEntry($entryId, $windowSeconds, 50);

        $durations = array_map(static fn (TraceSummary $t): float => $t->durationMs, $traces);
        sort($durations);

        $changes = $this->audit->forEntry($entryId, $windowSeconds);

        return [
            'window' => $windowSeconds,
            'requests' => [
                'count' => count($traces),
                'p95' => $this->percentile($durations, 0.95),
                'recent' => array_map(static fn (TraceSummary $t): array => [
                    'id' => $t->id,
                    'durationMs' => $t->durationMs,
                    'timestamp' => $t->timestampMs,
                ], array_slice($traces, 0, 8)),
            ],
            'changes' => array_map(static fn ($row): array => [
                'action' => $row->action,
                'timestamp' => $row->timestamp,
                'who' => $row->who,
                'changed' => $row->changed,
            ], array_slice($changes, 0, 8)),
        ];
    }

    /**
     * @param  list<float>  $sorted  ascending
     */
    private function percentile(array $sorted, float $p): float
    {
        if ($sorted === []) {
            return 0.0;
        }

        return round($sorted[(int) floor($p * (count($sorted) - 1))], 1);
    }
}
