<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Telemetry;

/**
 * Builds the metric section of the Telemetry page from the backend's
 * Prometheus API. The metric names and labels are the ones laravel-telemetry
 * and this addon emit — request histograms (`http_server_request_duration_seconds`)
 * and the Statamic counters (`statamic_static_cache_operations_total`,
 * `statamic_stache_warms_total`, `statamic_glide_generations_total`,
 * `statamic_forms_submissions_total`, `statamic_content_changes_total`, …).
 */
final class TelemetryMetrics
{
    private const REQUEST_BUCKET = 'http_server_request_duration_seconds_bucket';

    public function __construct(private readonly Metrics $metrics) {}

    public function overview(int $windowSeconds): MetricsOverview
    {
        $w = $windowSeconds.'s';

        return new MetricsOverview(
            requestTotal: $this->total('http_server_request_duration_seconds_count', $w),
            p50: $this->quantile(self::REQUEST_BUCKET, 0.50, $w),
            p95: $this->quantile(self::REQUEST_BUCKET, 0.95, $w),
            p99: $this->quantile(self::REQUEST_BUCKET, 0.99, $w),
            routes: $this->grouped('http_server_request_duration_seconds_count', 'http_route', $w, 'route'),
            cacheHitRatio: $this->cacheHitRatio(),
            cacheOperations: $this->cacheOperations(),
            stacheWarms: $this->total('statamic_stache_warms_total', $w),
            stacheClears: $this->total('statamic_stache_clears_total', $w),
            stacheWarmP95: $this->stacheWarmP95($w),
            glide: $this->grouped('statamic_glide_generations_total', 'preset', $w, 'preset'),
            glideClears: $this->total('statamic_glide_cache_clears_total', $w),
            forms: $this->grouped('statamic_forms_submissions_total', 'form', $w, 'form'),
            contentChanges: $this->contentChanges($w),
        );
    }

    /** `sum(increase(metric[w]))` — the total over the window. */
    private function total(string $metric, string $w): float
    {
        return round($this->metrics->scalar("sum(increase({$metric}[{$w}]))"));
    }

    /**
     * `histogram_quantile(q, …)` in milliseconds (the base unit is seconds),
     * over the window. 0.0 when the histogram has no observations.
     */
    private function quantile(string $bucket, float $q, string $w): float
    {
        $value = $this->metrics->scalar("histogram_quantile({$q}, sum by (le) (increase({$bucket}[{$w}])))");

        return $value > 0 ? round($value * 1000, 1) : 0.0;
    }

    /**
     * Group a counter by one label over the window, busiest first.
     *
     * @return list<array<string, string|float>>
     */
    private function grouped(string $metric, string $label, string $w, string $key): array
    {
        $series = $this->metrics->instant("sum by ({$label}) (increase({$metric}[{$w}]))");

        $rows = [];
        foreach ($series as $s) {
            if ($s['value'] > 0) {
                $rows[] = [$key => $s['labels'][$label] ?? '(unknown)', 'count' => round($s['value'])];
            }
        }

        usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice($rows, 0, 15);
    }

    /**
     * @return array<string, float>
     */
    private function cacheOperations(): array
    {
        $ops = [];
        foreach ($this->metrics->instant('sum by (operation) (statamic_static_cache_operations_total)') as $s) {
            $op = $s['labels']['operation'] ?? null;
            if (is_string($op)) {
                $ops[$op] = round($s['value']);
            }
        }

        ksort($ops);

        return $ops;
    }

    private function cacheHitRatio(): ?float
    {
        $ops = $this->cacheOperations();
        $total = ($ops['hit'] ?? 0.0) + ($ops['miss'] ?? 0.0);

        return $total > 0 ? round(($ops['hit'] ?? 0.0) / $total, 4) : null;
    }

    private function stacheWarmP95(string $w): ?float
    {
        $value = $this->metrics->scalar("histogram_quantile(0.95, sum by (le) (increase(statamic_stache_warm_duration_seconds_bucket[{$w}])))");

        return $value > 0 ? round($value * 1000, 1) : null;
    }

    /**
     * @return list<array{type: string, action: string, count: float}>
     */
    private function contentChanges(string $w): array
    {
        $series = $this->metrics->instant("sum by (type, action) (increase(statamic_content_changes_total[{$w}]))");

        $rows = [];
        foreach ($series as $s) {
            if ($s['value'] > 0) {
                $rows[] = [
                    'type' => $s['labels']['type'] ?? 'unknown',
                    'action' => $s['labels']['action'] ?? 'changed',
                    'count' => round($s['value']),
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $rows;
    }
}
