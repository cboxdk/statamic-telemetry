<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Telemetry;

/**
 * Builds the Activity section from the application-level metrics laravel-telemetry
 * emits beyond HTTP requests: queue jobs (`queue_jobs_processed_total`,
 * `queue_job_duration_seconds`), database work (`db_queries_total`,
 * `db_queries_n_plus_one_total`, `db_queries_duplicated_total`), filesystem
 * (`storage_operations_total`) and outgoing HTTP (`http_client_request_duration_seconds`).
 * All guarded — a panel whose metric isn't flowing simply comes back empty.
 */
final class ActivityMetrics
{
    private const POINTS = 48;

    /** Eval time for the current call; null = now (set by {@see overview()}). */
    private ?int $at = null;

    public function __construct(private readonly Metrics $metrics) {}

    /**
     * @return array{
     *   jobs: array{processed: float, dispatched: float, utilization: ?float, durationP95: float, byJob: list<array{job: string, queue: string, count: float, p95: float}>, series: array{t: list<int>, values: list<float>}},
     *   queries: array{total: float, nPlusOne: float, duplicated: float, byConnection: list<array{connection: string, count: float}>, series: array{t: list<int>, values: list<float>}},
     *   storage: array{total: float, operations: list<array{disk: string, operation: string, count: float, share: float}>},
     *   http: array{total: float, errorRate: float, p95: float, byHost: list<array{host: string, count: float, errors: float, p95: float}>, series: array{t: list<int>, values: list<float>}}
     * }
     */
    public function overview(int $windowSeconds, ?int $endsAt = null): array
    {
        $this->at = $endsAt;
        $w = $windowSeconds.'s';

        return [
            'jobs' => $this->jobs($windowSeconds, $w),
            'queries' => $this->queries($windowSeconds, $w),
            'storage' => $this->storage($w),
            'http' => $this->http($windowSeconds, $w),
        ];
    }

    /**
     * One area on its own — the Jobs / Queries / Outgoing HTTP pages each need
     * only theirs, not the four together.
     *
     * @return array<string, mixed>
     */
    public function area(string $area, int $windowSeconds, ?int $endsAt = null): array
    {
        $this->at = $endsAt;
        $w = $windowSeconds.'s';

        return match ($area) {
            'jobs' => $this->jobs($windowSeconds, $w),
            'queries' => $this->queries($windowSeconds, $w),
            'storage' => $this->storage($w),
            'http' => $this->http($windowSeconds, $w),
            default => throw new \InvalidArgumentException("Unknown activity area [{$area}]."),
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $activity
     */
    public function hasData(array $activity): bool
    {
        return $activity['jobs']['processed'] > 0
            || $activity['queries']['total'] > 0
            || $activity['storage']['total'] > 0
            || $activity['http']['total'] > 0;
    }

    /**
     * @return array{processed: float, dispatched: float, utilization: ?float, durationP95: float, byJob: list<array{job: string, queue: string, count: float, p95: float}>, series: array{t: list<int>, values: list<float>}}
     */
    private function jobs(int $windowSeconds, string $w): array
    {
        $util = $this->metrics->instant('queue_metrics_workers_utilization_percent{window="current"}', $this->at);

        $p95 = $this->mapBy(['job_name'], 'histogram_quantile(0.95, sum by (job_name, le) (increase(queue_job_duration_seconds_bucket['.$w.'])))', 1000);

        $byJob = [];
        foreach ($this->metrics->instant("sum by (job_name, queue) (increase(queue_jobs_processed_total[{$w}]))", $this->at) as $s) {
            if ($s['value'] > 0) {
                $job = $s['labels']['job_name'] ?? '(unknown)';
                $byJob[] = [
                    'job' => $this->shortClass($job),
                    'queue' => $s['labels']['queue'] ?? 'default',
                    'count' => round($s['value']),
                    'p95' => round($p95[$job] ?? 0.0, 1),
                ];
            }
        }
        usort($byJob, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'processed' => $this->total('queue_jobs_processed_total', $w),
            'dispatched' => $this->total('queue_jobs_dispatched_total', $w),
            'utilization' => $util === [] ? null : round($util[0]['value'], 1),
            'durationP95' => $this->quantile('queue_job_duration_seconds_bucket', $w),
            'byJob' => array_slice($byJob, 0, 12),
            'series' => $this->series('queue_jobs_processed_total', $windowSeconds),
        ];
    }

    /**
     * @return array{total: float, nPlusOne: float, duplicated: float, byConnection: list<array{connection: string, count: float}>, series: array{t: list<int>, values: list<float>}}
     */
    private function queries(int $windowSeconds, string $w): array
    {
        $byConnection = [];
        foreach ($this->metrics->instant("sum by (connection) (increase(db_queries_total[{$w}]))", $this->at) as $s) {
            if ($s['value'] > 0) {
                $byConnection[] = ['connection' => $s['labels']['connection'] ?? 'default', 'count' => round($s['value'])];
            }
        }
        usort($byConnection, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'total' => $this->total('db_queries_total', $w),
            'nPlusOne' => $this->total('db_queries_n_plus_one_total', $w),
            'duplicated' => $this->total('db_queries_duplicated_total', $w),
            'byConnection' => $byConnection,
            'series' => $this->series('db_queries_total', $windowSeconds),
        ];
    }

    /**
     * @return array{total: float, operations: list<array{disk: string, operation: string, count: float, share: float}>}
     */
    private function storage(string $w): array
    {
        $rows = [];
        $total = 0.0;
        foreach ($this->metrics->instant("sum by (disk, operation) (increase(storage_operations_total[{$w}]))", $this->at) as $s) {
            if ($s['value'] > 0) {
                $count = round($s['value']);
                $total += $count;
                $rows[] = ['disk' => $s['labels']['disk'] ?? '(disk)', 'operation' => $s['labels']['operation'] ?? '(op)', 'count' => $count, 'share' => 0.0];
            }
        }

        foreach ($rows as &$row) {
            $row['share'] = $total > 0 ? round($row['count'] / $total, 4) : 0.0;
        }
        unset($row);

        usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return ['total' => $total, 'operations' => $rows];
    }

    /**
     * @return array{total: float, errorRate: float, p95: float, byHost: list<array{host: string, count: float, errors: float, p95: float}>, series: array{t: list<int>, values: list<float>}}
     */
    private function http(int $windowSeconds, string $w): array
    {
        $metric = 'http_client_request_duration_seconds_count';
        $total = $this->total($metric, $w);
        $errors = $this->total($metric.'{error_type!=""}', $w);
        $p95ByHost = $this->mapBy(['server_address'], 'histogram_quantile(0.95, sum by (server_address, le) (increase(http_client_request_duration_seconds_bucket['.$w.'])))', 1000);
        $errByHost = $this->mapBy(['server_address'], 'sum by (server_address) (increase('.$metric.'{error_type!=""}['.$w.']))');

        $byHost = [];
        foreach ($this->metrics->instant("sum by (server_address) (increase({$metric}[{$w}]))", $this->at) as $s) {
            if ($s['value'] > 0) {
                $host = $s['labels']['server_address'] ?? '(host)';
                $byHost[] = [
                    'host' => $host,
                    'count' => round($s['value']),
                    'errors' => round($errByHost[$host] ?? 0.0),
                    'p95' => round($p95ByHost[$host] ?? 0.0, 1),
                ];
            }
        }
        usort($byHost, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'total' => $total,
            'errorRate' => $total > 0 ? round($errors / $total, 4) : 0.0,
            'p95' => $this->quantile('http_client_request_duration_seconds_bucket', $w),
            'byHost' => array_slice($byHost, 0, 12),
            'series' => $this->series($metric, $windowSeconds),
        ];
    }

    /** `sum(increase(metric[w]))`. */
    private function total(string $metric, string $w): float
    {
        return round($this->metrics->scalar("sum(increase({$metric}[{$w}]))", $this->at));
    }

    /** `histogram_quantile(0.95, …)` of a histogram, in ms. */
    private function quantile(string $bucket, string $w): float
    {
        $value = $this->metrics->scalar("histogram_quantile(0.95, sum by (le) (increase({$bucket}[{$w}])))", $this->at);

        return $value > 0 ? round($value * 1000, 1) : 0.0;
    }

    /**
     * A single-series `sum(increase(metric[step]))` over time with its x axis.
     *
     * @return array{t: list<int>, values: list<float>}
     */
    private function series(string $metric, int $windowSeconds): array
    {
        $step = max(15, (int) ($windowSeconds / self::POINTS));
        $range = $this->metrics->range("sum(increase({$metric}[{$step}s]))", $windowSeconds, $step, $this->at);

        if ($range === []) {
            return ['t' => [], 'values' => []];
        }

        $t = [];
        $values = [];
        foreach ($range[0]['points'] as [$ts, $v]) {
            $t[] = (int) $ts;
            $values[] = round((float) $v, 1);
        }

        return ['t' => $t, 'values' => $values];
    }

    /**
     * An instant query reduced to a first-label → value map (optionally scaled).
     *
     * @param  list<string>  $labels
     * @return array<string, float>
     */
    private function mapBy(array $labels, string $query, float $scale = 1.0): array
    {
        $key = $labels[0];
        $out = [];
        foreach ($this->metrics->instant($query, $this->at) as $s) {
            $value = $s['labels'][$key] ?? '';
            if ($value !== '' && $s['value'] > 0) {
                $out[$value] = $s['value'] * $scale;
            }
        }

        return $out;
    }

    /** The trailing class name of a fully-qualified job class. */
    private function shortClass(string $job): string
    {
        $pos = strrpos($job, '\\');

        return $pos === false ? $job : substr($job, $pos + 1);
    }
}
