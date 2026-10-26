<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Telemetry;

use Cbox\StatamicTelemetry\Http\Controllers\Cp\ExploreController;

/**
 * Builds the metric section of the Telemetry dashboard from the backend's
 * Prometheus API. The metric names and labels are the ones laravel-telemetry
 * and this addon emit — request histograms (`http_server_request_duration_seconds`
 * with `http_response_status_code` / `http_route`) and the Statamic counters
 * (`statamic_static_cache_operations_total`, `statamic_glide_generations_total`,
 * … — read by the area pages through increase() / countsBy() / increaseSeries()).
 */
final class TelemetryMetrics
{
    private const COUNT = 'http_server_request_duration_seconds_count';

    private const SUM = 'http_server_request_duration_seconds_sum';

    private const BUCKET = 'http_server_request_duration_seconds_bucket';

    /** Target number of points across a chart's window. */
    private const POINTS = 48;

    /** Eval time (unix seconds) for the current call; null = now. Set by the
     *  public entry points so a brushed custom range resolves at its own end. */
    private ?int $at = null;

    public function __construct(private readonly Metrics $metrics) {}

    public function overview(int $windowSeconds, ?int $endsAt = null): MetricsOverview
    {
        $this->at = $endsAt;
        $w = $windowSeconds.'s';
        $classes = $this->statusClasses($w);
        $total = array_sum($classes);
        $requests = $total > 0 ? $total : $this->total(self::COUNT, $w);
        $errorRate = $total > 0 ? round(($classes['5xx'] ?? 0.0) / $total, 4) : 0.0;
        $avg = $this->avg($w);
        $p95 = $this->quantile(0.95, $w);

        return new MetricsOverview(
            requestTotal: $requests,
            errorRate: $errorRate,
            statusClasses: $classes,
            statusSeries: $this->statusSeries($windowSeconds),
            avg: $avg,
            p50: $this->quantile(0.50, $w),
            p95: $p95,
            p99: $this->quantile(0.99, $w),
            durationSeries: $this->durationSeries($windowSeconds),
            delta: $this->delta($windowSeconds, $requests, $errorRate, $avg, $p95),
            routes: $this->routes($windowSeconds),
        );
    }

    /**
     * The Explore request list's stat header: total requests, server-error
     * rate and latency percentiles over the window.
     *
     * @return array{requests: float, errorRate: float, p50: float, p95: float}
     */
    public function summary(int $windowSeconds, ?int $endsAt = null): array
    {
        $this->at = $endsAt;
        $w = $windowSeconds.'s';
        $classes = $this->statusClasses($w);
        $total = array_sum($classes);

        return [
            'requests' => $total > 0 ? $total : $this->total(self::COUNT, $w),
            'errorRate' => $total > 0 ? round(($classes['5xx'] ?? 0.0) / $total, 4) : 0.0,
            'p50' => $this->quantile(0.50, $w),
            'p95' => $this->quantile(0.95, $w),
        ];
    }

    /**
     * Facet values with counts for the Explore request list — status code,
     * method and route, each busiest first. Clicking one filters the list by
     * the matching span attribute (see {@see ExploreController}).
     *
     * @return array{status: list<array{value: string, count: float}>, method: list<array{value: string, count: float}>, route: list<array{value: string, count: float}>}
     */
    public function facets(int $windowSeconds, ?int $endsAt = null): array
    {
        $this->at = $endsAt;
        $w = $windowSeconds.'s';

        return [
            'status' => $this->facet('http_response_status_code', $w, 8),
            'method' => $this->facet('http_request_method', $w, 6),
            'route' => $this->facet('http_route', $w, 15),
        ];
    }

    /** Total increase of a counter over the window. */
    public function increase(string $metric, int $windowSeconds, ?int $endsAt = null): float
    {
        $this->at = $endsAt;

        return $this->total($metric, $windowSeconds.'s');
    }

    /**
     * A counter's increase over the window split by one label, busiest
     * first — the breakdown table behind a stat tile.
     *
     * @return array<string, float>
     */
    public function countsBy(string $metric, string $label, int $windowSeconds, ?int $endsAt = null): array
    {
        $this->at = $endsAt;

        $counts = $this->mapBy($label, "sum by ({$label}) (increase({$metric}[{$windowSeconds}s]))");
        arsort($counts);

        return array_map(static fn (float $value): float => round($value), $counts);
    }

    /**
     * A counter over time on the chart grid, optionally split by a label
     * (one series per value, stackable).
     *
     * @return array{t: list<int>, series: array<string, list<float>>}
     */
    public function increaseSeries(string $metric, ?string $by, int $windowSeconds, ?int $endsAt = null): array
    {
        $this->at = $endsAt;
        $step = $this->step($windowSeconds);
        $query = $by === null
            ? "sum(increase({$metric}[{$step}s]))"
            : "sum by ({$by}) (increase({$metric}[{$step}s]))";

        $range = $this->metrics->range($query, $windowSeconds, $step, $this->at);
        $grid = $this->grid($range);

        $series = [];
        foreach ($range as $s) {
            $name = $by === null ? 'total' : ($s['labels'][$by] ?? '(unknown)');
            $byTs = [];
            foreach ($s['points'] as [$ts, $value]) {
                $byTs[(int) $ts] = ($byTs[(int) $ts] ?? 0.0) + (float) $value;
            }
            $series[$name] = $this->onGrid($byTs, $grid);
        }
        ksort($series);

        return ['t' => $grid, 'series' => $series];
    }

    /** p95 of a histogram over the window, in ms — null without samples. */
    public function p95Ms(string $bucketMetric, int $windowSeconds, ?int $endsAt = null): ?float
    {
        $this->at = $endsAt;
        $value = $this->metrics->scalar("histogram_quantile(0.95, sum by (le) (increase({$bucketMetric}[{$windowSeconds}s])))", $this->at);

        return $value > 0 ? round($value * 1000, 1) : null;
    }

    /**
     * Stacked request throughput over the window, for the Explore chart.
     *
     * @return array{t: list<int>, ok: list<float>, client: list<float>, server: list<float>}
     */
    public function throughput(int $windowSeconds, ?int $endsAt = null): array
    {
        $this->at = $endsAt;

        return $this->statusSeries($windowSeconds);
    }

    /**
     * Aggregate requests by a dimension (an http metric label) over the window:
     * per value, the request count, server errors and rate, and p50/p95. This
     * is the Explore "group by" — telemetry-ui's GroupTable — busiest first.
     *
     * @return list<array{value: string, count: float, errors: float, errorRate: float, p50: float, p95: float}>
     */
    public function groupBy(string $label, int $windowSeconds, ?int $endsAt = null): array
    {
        $this->at = $endsAt;
        $w = $windowSeconds.'s';

        $counts = $this->mapBy($label, "sum by ({$label}) (increase(".self::COUNT."[{$w}]))");
        if ($counts === []) {
            return [];
        }

        $errors = $this->mapBy($label, "sum by ({$label}) (increase(".self::COUNT.'{http_response_status_code=~"5.."}'."[{$w}]))");
        $p50 = $this->mapBy($label, "histogram_quantile(0.5, sum by ({$label}, le) (increase(".self::BUCKET."[{$w}])))", 1000);
        $p95 = $this->mapBy($label, "histogram_quantile(0.95, sum by ({$label}, le) (increase(".self::BUCKET."[{$w}])))", 1000);

        $rows = [];
        foreach ($counts as $value => $count) {
            if ($count <= 0) {
                continue;
            }

            $err = $errors[$value] ?? 0.0;
            $rows[] = [
                'value' => $value,
                'count' => round($count),
                'errors' => round($err),
                'errorRate' => round($err / $count, 4),
                'p50' => round($p50[$value] ?? 0.0, 1),
                'p95' => round($p95[$value] ?? 0.0, 1),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice($rows, 0, 50);
    }

    /**
     * One facet's values with counts, busiest first.
     *
     * @return list<array{value: string, count: float}>
     */
    private function facet(string $label, string $w, int $limit): array
    {
        $rows = [];
        foreach ($this->metrics->instant("sum by ({$label}) (increase(".self::COUNT."[{$w}]))", $this->at) as $s) {
            $value = $s['labels'][$label] ?? '';
            if ($value !== '' && $s['value'] > 0) {
                $rows[] = ['value' => $value, 'count' => round($s['value'])];
            }
        }

        usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice($rows, 0, $limit);
    }

    /**
     * Request volume bucketed into response-status classes (1xx…5xx) over the
     * window. Every known class is present (0 when unused) so the UI can show a
     * stable split.
     *
     * @return array<string, float>
     */
    private function statusClasses(string $w): array
    {
        $classes = ['1xx' => 0.0, '2xx' => 0.0, '3xx' => 0.0, '4xx' => 0.0, '5xx' => 0.0];

        foreach ($this->metrics->instant('sum by (http_response_status_code) (increase('.self::COUNT."[{$w}]))", $this->at) as $s) {
            $class = $this->classOf($s['labels']['http_response_status_code'] ?? '');
            if ($class !== null) {
                $classes[$class] += round($s['value']);
            }
        }

        return $classes;
    }

    /**
     * Stacked throughput over time — requests per step, folded into the three
     * bands telemetry-ui shows: ok (1/2/3xx), client (4xx) and server (5xx).
     * Series are aligned onto a shared timestamp grid (unix seconds) so they
     * stack cleanly and carry an x axis for the chart.
     *
     * @return array{t: list<int>, ok: list<float>, client: list<float>, server: list<float>}
     */
    private function statusSeries(int $windowSeconds): array
    {
        $step = $this->step($windowSeconds);
        $range = $this->metrics->range(
            'sum by (http_response_status_code) (increase('.self::COUNT."[{$step}s]))",
            $windowSeconds,
            $step,
            $this->at,
        );

        $bands = ['ok' => [], 'client' => [], 'server' => []];
        foreach ($range as $series) {
            $band = $this->bandOf($series['labels']['http_response_status_code'] ?? '');
            foreach ($series['points'] as [$ts, $value]) {
                $bands[$band][(int) $ts] = ($bands[$band][(int) $ts] ?? 0.0) + $value;
            }
        }

        $grid = $this->grid($range);

        return [
            't' => $grid,
            'ok' => $this->onGrid($bands['ok'], $grid),
            'client' => $this->onGrid($bands['client'], $grid),
            'server' => $this->onGrid($bands['server'], $grid),
        ];
    }

    /**
     * Average and p95 latency (ms) over time, for the Duration chart, on a
     * shared timestamp axis.
     *
     * The mean is `sum / count`, but telemetryd caps how many samples one query
     * may scan, and dividing the two full-cardinality histogram metrics in a
     * single range query blows past it on a wide window. So the rate of each is
     * fetched separately (each stays under the cap) and divided per timestamp
     * here. A small fixed rate window keeps every query cheap.
     *
     * @return array{t: list<int>, avg: list<float>, p95: list<float>}
     */
    private function durationSeries(int $windowSeconds): array
    {
        $step = $this->step($windowSeconds);
        $rw = max(120, min(300, $step)).'s';

        $sum = $this->pointMap($this->metrics->range('sum(rate('.self::SUM."[{$rw}]))", $windowSeconds, $step, $this->at));
        $count = $this->pointMap($this->metrics->range('sum(rate('.self::COUNT."[{$rw}]))", $windowSeconds, $step, $this->at));
        $p95 = $this->pointMap($this->metrics->range('histogram_quantile(0.95, sum by (le) (rate('.self::BUCKET."[{$rw}])))", $windowSeconds, $step, $this->at));

        $grid = array_keys($sum + $count + $p95);
        sort($grid);

        $avg = [];
        $p95out = [];
        foreach ($grid as $t) {
            $c = $count[$t] ?? 0.0;
            $avg[] = $c > 0 ? round(($sum[$t] ?? 0.0) / $c * 1000, 1) : 0.0;
            $p95out[] = ($p95[$t] ?? 0.0) > 0 ? round($p95[$t] * 1000, 1) : 0.0;
        }

        return ['t' => $grid, 'avg' => $avg, 'p95' => $p95out];
    }

    /**
     * The first series of a range result as a timestamp → value map.
     *
     * @param  list<array{labels: array<string, string>, points: list<array{0: float, 1: float}>}>  $range
     * @return array<int, float>
     */
    private function pointMap(array $range): array
    {
        if ($range === []) {
            return [];
        }

        $map = [];
        foreach ($range[0]['points'] as [$t, $v]) {
            $map[(int) $t] = (float) $v;
        }

        return $map;
    }

    /**
     * Period-over-period change for the headline metrics: the same window,
     * shifted back by one window via PromQL `offset`. Each is a signed fraction
     * (current − previous) / previous, or null when there is no prior data.
     *
     * @return array{requests: ?float, errorRate: ?float, avg: ?float, p95: ?float}
     */
    private function delta(int $windowSeconds, float $requests, float $errorRate, float $avg, float $p95): array
    {
        $w = $windowSeconds.'s';
        $o = "{$w} offset {$w}";

        $prevRequests = round($this->metrics->scalar('sum(increase('.self::COUNT."[{$o}]))", $this->at));
        $prev5xx = round($this->metrics->scalar('sum(increase('.self::COUNT.'{http_response_status_code=~"5.."}'."[{$o}]))", $this->at));
        $prevErrorRate = $prevRequests > 0 ? round($prev5xx / $prevRequests, 4) : null;
        $prevAvgRaw = $this->metrics->scalar('sum(increase('.self::SUM."[{$o}])) / sum(increase(".self::COUNT."[{$o}]))", $this->at);
        $prevAvg = $prevAvgRaw > 0 ? round($prevAvgRaw * 1000, 1) : 0.0;
        $prevP95Raw = $this->metrics->scalar('histogram_quantile(0.95, sum by (le) (increase('.self::BUCKET."[{$o}])))", $this->at);
        $prevP95 = $prevP95Raw > 0 ? round($prevP95Raw * 1000, 1) : 0.0;

        return [
            'requests' => $this->change($requests, $prevRequests),
            'errorRate' => $prevErrorRate === null ? null : $this->change($errorRate, $prevErrorRate, false),
            'avg' => $this->change($avg, $prevAvg),
            'p95' => $this->change($p95, $prevP95),
        ];
    }

    /** Signed fractional change, null when there is no prior value to compare. */
    private function change(float $current, float $previous, bool $relative = true): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return round($relative ? ($current - $previous) / $previous : $current - $previous, 4);
    }

    /**
     * The routes that need attention: per `http_route`, request total, server
     * errors and their rate, p95, and a throughput sparkline. Ordered by server
     * errors then p95 — the ones a regression shows up in first.
     *
     * @return list<array{route: string, total: float, errors: float, errorRate: float, p95: float, trend: list<float>}>
     */
    private function routes(int $windowSeconds): array
    {
        $w = $windowSeconds.'s';

        $totals = $this->mapBy('http_route', 'sum by (http_route) (increase('.self::COUNT."[{$w}]))");
        if ($totals === []) {
            return [];
        }

        $errors = $this->mapBy('http_route', 'sum by (http_route) (increase('.self::COUNT.'{http_response_status_code=~"5.."}'."[{$w}]))");
        $p95 = $this->mapBy('http_route', 'histogram_quantile(0.95, sum by (http_route, le) (increase('.self::BUCKET."[{$w}])))", 1000);
        $trends = $this->trends($windowSeconds);

        $rows = [];
        foreach ($totals as $route => $total) {
            if ($total <= 0) {
                continue;
            }

            $err = $errors[$route] ?? 0.0;
            $rows[] = [
                'route' => $route,
                'total' => round($total),
                'errors' => round($err),
                'errorRate' => $total > 0 ? round($err / $total, 4) : 0.0,
                'p95' => round($p95[$route] ?? 0.0, 1),
                'trend' => $trends[$route] ?? [],
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$b['errors'], $b['p95']] <=> [$a['errors'], $a['p95']]);

        return array_slice($rows, 0, 12);
    }

    /**
     * Per-route throughput sparkline from one range query.
     *
     * @return array<string, list<float>>
     */
    private function trends(int $windowSeconds): array
    {
        $step = $this->step($windowSeconds);
        $range = $this->metrics->range(
            'sum by (http_route) (increase('.self::COUNT."[{$step}s]))",
            $windowSeconds,
            $step,
            $this->at,
        );

        $out = [];
        foreach ($range as $series) {
            $route = $series['labels']['http_route'] ?? null;
            if (is_string($route)) {
                $out[$route] = array_map(static fn (array $p): float => round($p[1], 1), $series['points']);
            }
        }

        return $out;
    }

    /** `sum(increase(metric[w]))` — the total over the window. */
    private function total(string $metric, string $w): float
    {
        return round($this->metrics->scalar("sum(increase({$metric}[{$w}]))", $this->at));
    }

    /** Mean request latency (ms) over the window, 0.0 with no observations. */
    private function avg(string $w): float
    {
        $value = $this->metrics->scalar('sum(increase('.self::SUM."[{$w}])) / sum(increase(".self::COUNT."[{$w}]))", $this->at);

        return $value > 0 ? round($value * 1000, 1) : 0.0;
    }

    /**
     * `histogram_quantile(q, …)` of the request histogram in milliseconds
     * (the base unit is seconds), over the window. 0.0 with no observations.
     */
    private function quantile(float $q, string $w): float
    {
        $value = $this->metrics->scalar("histogram_quantile({$q}, sum by (le) (increase(".self::BUCKET."[{$w}])))", $this->at);

        return $value > 0 ? round($value * 1000, 1) : 0.0;
    }

    /**
     * An instant query reduced to a label → value map (optionally scaled, e.g.
     * seconds → ms), dropping empty labels.
     *
     * @return array<string, float>
     */
    private function mapBy(string $label, string $query, float $scale = 1.0): array
    {
        $out = [];
        foreach ($this->metrics->instant($query, $this->at) as $s) {
            $key = $s['labels'][$label] ?? '';
            if ($key !== '' && $s['value'] > 0) {
                $out[$key] = $s['value'] * $scale;
            }
        }

        return $out;
    }

    /** Step (seconds) for a window's chart — ~POINTS points, never below 15s. */
    private function step(int $windowSeconds): int
    {
        return max(15, (int) ($windowSeconds / self::POINTS));
    }

    /**
     * The sorted union of timestamps across a range result — a shared x grid.
     *
     * @param  list<array{labels: array<string, string>, points: list<array{0: float, 1: float}>}>  $range
     * @return list<int>
     */
    private function grid(array $range): array
    {
        $ts = [];
        foreach ($range as $series) {
            foreach ($series['points'] as [$t]) {
                $ts[(int) $t] = true;
            }
        }

        $keys = array_keys($ts);
        sort($keys);

        return $keys;
    }

    /**
     * Project a {timestamp → value} map onto the grid, 0.0 where it has no point.
     *
     * @param  array<int, float>  $byTs
     * @param  list<int>  $grid
     * @return list<float>
     */
    private function onGrid(array $byTs, array $grid): array
    {
        return array_map(static fn (int $t): float => round($byTs[$t] ?? 0.0, 1), $grid);
    }

    /** The status class ("2xx", …) of a status code, or null when unparseable. */
    private function classOf(string $code): ?string
    {
        $digit = $code[0] ?? '';

        return ctype_digit($digit) && $digit >= '1' && $digit <= '5' ? $digit.'xx' : null;
    }

    /** The stacked band a status code belongs to: ok (1/2/3xx), client (4xx), server (5xx). */
    private function bandOf(string $code): string
    {
        return match ($code[0] ?? '') {
            '4' => 'client',
            '5' => 'server',
            default => 'ok',
        };
    }
}
