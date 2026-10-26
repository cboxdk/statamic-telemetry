<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Telemetry;

use Cbox\StatamicTelemetry\Audit\AuditLog;
use Cbox\StatamicTelemetry\Audit\AuditRow;
use Illuminate\Support\Facades\Gate;

/**
 * One entry's telemetry story — the Telemetry tab in the entry editor: how
 * often this page was requested and how fast (every request carries the
 * entry's id on its root span), its status mix, how the static cache treated
 * it, the exceptions thrown on its path, and who changed it. Everything
 * row-level is there too (recent requests open their trace), and the latency
 * is set against the site's. Guarded — the readers return empty when the
 * backend is down.
 */
final class EntryTelemetry
{
    /** Requests fetched per window (the most recent). */
    private const LIMIT = 300;

    private const BUCKETS = 24;

    public function __construct(
        private readonly Traces $traces,
        private readonly Logs $logs,
        private readonly AuditLog $audit,
        private readonly TelemetryMetrics $metrics,
    ) {}

    /**
     * @param  ?string  $path  the entry's URI (`/about`), for the exceptions logged against it
     * @return array<string, mixed>
     */
    public function story(string $entryId, ?string $path, TimeRange $range): array
    {
        $s = $range->seconds;
        $at = $range->endsAt;

        $traces = $this->traces->byEntry($entryId, $s, self::LIMIT, $at);
        $previous = $this->traces->byEntry($entryId, $s, self::LIMIT, ($at ?? time()) - $s);

        $durations = array_map(static fn (TraceSummary $t): float => $t->durationMs, $traces);
        sort($durations);
        $previousDurations = array_map(static fn (TraceSummary $t): float => $t->durationMs, $previous);
        sort($previousDurations);

        $mix = ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0];
        $cache = ['hit' => 0, 'miss' => 0, 'write' => 0, 'other' => 0];
        $lastWrite = null;
        foreach ($traces as $t) {
            $class = self::classOf($t->attr('http.response.status_code'));
            if ($class !== null) {
                $mix[$class]++;
            }
            $outcome = $t->attr('statamic.static_cache');
            if ($outcome !== null) {
                $cache[isset($cache[$outcome]) ? $outcome : 'other']++;
                if ($outcome === 'write') {
                    $lastWrite = max($lastWrite ?? 0, $t->timestampMs);
                }
            }
        }

        $count = count($traces);
        $served = $cache['hit'] + $cache['miss'];
        $siteP95 = $count > 0 ? $this->metrics->summary($s, $at)['p95'] : 0.0;

        $exceptions = $path !== null && $path !== '' ? $this->logs->withLabel('url_path', $path, 'error', $s, 50, $at) : [];
        // The audit trail has its own permission, on the entry tab as on the Content page.
        $auditable = Gate::allows('view content audit log');
        $changes = $auditable ? $this->audit->forEntry($entryId, $s) : [];

        return [
            'requests' => [
                'count' => $count,
                'previous' => count($previous),
                'errorRate' => $count > 0 ? round($mix['5xx'] / $count, 4) : 0.0,
                'avg' => $count > 0 ? round(array_sum($durations) / $count, 1) : 0.0,
                'p50' => $this->percentile($durations, 0.50),
                'p95' => $this->percentile($durations, 0.95),
                'previousP95' => $this->percentile($previousDurations, 0.95),
                'siteP95' => $siteP95 > 0 ? round($siteP95, 1) : null,
                'statusMix' => $mix,
                'series' => $this->series($traces, $range),
                'recent' => array_map(static fn (TraceSummary $t): array => $t->toRow(), array_slice($traces, 0, 25)),
                'capped' => $count >= self::LIMIT,
            ],
            'cache' => [
                ...$cache,
                'ratio' => $served > 0 ? round($cache['hit'] / $served, 4) : null,
                'lastWrite' => $lastWrite,
            ],
            'exceptions' => [
                'count' => count($exceptions),
                'recent' => array_map(static fn (array $log): array => [
                    'timestamp' => $log['timestamp'],
                    'type' => self::shortClass($log['labels']['exception_type'] ?? ($log['line'] === 'exception' ? 'Exception' : $log['line'])),
                    'message' => $log['labels']['exception_message'] ?? ($log['line'] === 'exception' ? '' : $log['line']),
                    'origin' => isset($log['labels']['exception_file']) ? basename($log['labels']['exception_file']).(isset($log['labels']['exception_line']) ? ':'.$log['labels']['exception_line'] : '') : null,
                    'traceId' => $log['traceId'],
                ], array_slice($exceptions, 0, 10)),
            ],
            'changes' => [
                'available' => $auditable,
                'count' => count($changes),
                'rows' => array_map(static fn (AuditRow $row): AuditRow => $row, array_slice($changes, 0, 25)),
            ],
        ];
    }

    /**
     * Requests per bucket by status class, plus the bucket's p95 — the two
     * charts on the tab, on one axis.
     *
     * @param  list<TraceSummary>  $traces
     * @return array{t: list<int>, ok: list<int>, client: list<int>, server: list<int>, p95: list<float>}
     */
    private function series(array $traces, TimeRange $range): array
    {
        $start = $range->from();
        $width = max(1, (int) ceil($range->seconds / self::BUCKETS));
        $t = [];
        $ok = array_fill(0, self::BUCKETS, 0);
        $client = $ok;
        $server = $ok;
        $durations = array_fill(0, self::BUCKETS, []);

        for ($i = 0; $i < self::BUCKETS; $i++) {
            $t[] = $start + $i * $width;
        }

        $end = $range->to();
        foreach ($traces as $trace) {
            $seconds = $trace->timestampMs / 1000;
            if ($seconds < $start || $seconds > $end) {
                continue;
            }
            // The last instant of the window lands in the last bucket, not past it.
            $pos = min(self::BUCKETS - 1, (int) (($seconds - $start) / $width));
            $class = self::classOf($trace->attr('http.response.status_code'));
            match ($class) {
                '4xx' => $client[$pos]++,
                '5xx' => $server[$pos]++,
                default => $ok[$pos]++,
            };
            $durations[$pos][] = $trace->durationMs;
        }

        $p95 = [];
        foreach ($durations as $bucket) {
            sort($bucket);
            $p95[] = $this->percentile($bucket, 0.95);
        }

        return ['t' => $t, 'ok' => array_values($ok), 'client' => array_values($client), 'server' => array_values($server), 'p95' => $p95];
    }

    private static function classOf(?string $status): ?string
    {
        $code = (int) $status;

        return $code >= 200 && $code < 600 ? intdiv($code, 100).'xx' : null;
    }

    private static function shortClass(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
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
