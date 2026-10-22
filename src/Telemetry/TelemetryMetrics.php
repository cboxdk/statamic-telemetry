<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Telemetry;

/**
 * Builds the metric section of the Telemetry page from the backend's
 * Prometheus API — requests by content route, static-cache hit ratio and
 * content changes. The metric names and labels are the ones laravel-telemetry
 * and this addon emit (`http_server_request_duration_seconds`,
 * `statamic_static_cache_operations_total`, `statamic_content_changes_total`);
 * `http_route` carries the addon's bounded content route.
 */
final class TelemetryMetrics
{
    public function __construct(private readonly Metrics $metrics) {}

    public function overview(int $windowSeconds): MetricsOverview
    {
        return new MetricsOverview(
            routes: $this->routes($windowSeconds),
            cacheHitRatio: $this->cacheHitRatio(),
            cacheOperations: $this->cacheOperations(),
            contentChanges: $this->contentChanges($windowSeconds),
        );
    }

    /**
     * Requests grouped by route over the window, busiest first.
     *
     * @return list<array{route: string, count: float}>
     */
    private function routes(int $windowSeconds): array
    {
        $w = $windowSeconds.'s';
        $series = $this->metrics->instant("sum by (http_route) (increase(http_server_request_duration_seconds_count[{$w}]))");

        $rows = [];
        foreach ($series as $s) {
            $route = $s['labels']['http_route'] ?? '(unknown)';
            if ($s['value'] > 0) {
                $rows[] = ['route' => $route, 'count' => round($s['value'])];
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
        $hit = $ops['hit'] ?? 0.0;
        $miss = $ops['miss'] ?? 0.0;
        $total = $hit + $miss;

        return $total > 0 ? round($hit / $total, 4) : null;
    }

    /**
     * @return list<array{type: string, action: string, count: float}>
     */
    private function contentChanges(int $windowSeconds): array
    {
        $w = $windowSeconds.'s';
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
