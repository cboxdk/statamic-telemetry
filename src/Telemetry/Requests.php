<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Telemetry;

/**
 * Builds the Requests section from recent traces: volume and latency
 * percentiles, the per-bucket chart series, the slowest endpoints and the most
 * recent requests. Pure computation over {@see Traces::search()} — telemetry-ui
 * does this from Prometheus; here we derive it from the trace window until the
 * metric pipeline lands (Phase 2).
 */
final class Requests
{
    private const BUCKETS = 32;

    private const FETCH = 500;

    public function __construct(private readonly Traces $traces) {}

    public function overview(int $windowSeconds): RequestsOverview
    {
        $traces = $this->traces->search($windowSeconds, self::FETCH);

        if ($traces === []) {
            return new RequestsOverview(0, 0.0, 0.0, 0.0, 0.0, [], [], [], []);
        }

        $durations = array_map(static fn (TraceSummary $t): float => $t->durationMs, $traces);
        sort($durations);

        $count = count($traces);
        $minutes = max(1.0, $windowSeconds / 60);

        [$countSeries, $latencySeries] = $this->series($traces, $windowSeconds);

        return new RequestsOverview(
            count: $count,
            perMinute: round($count / $minutes, 1),
            p50: $this->percentile($durations, 0.50),
            p95: $this->percentile($durations, 0.95),
            maxMs: round(max($durations), 1),
            countSeries: $countSeries,
            latencySeries: $latencySeries,
            endpoints: $this->endpoints($traces),
            recent: array_slice($traces, 0, 25),
        );
    }

    /**
     * Per-bucket request count and average latency across the window.
     *
     * @param  list<TraceSummary>  $traces
     * @return array{0: list<int>, 1: list<float>}
     */
    private function series(array $traces, int $windowSeconds): array
    {
        $end = time() * 1000;
        $start = $end - $windowSeconds * 1000;
        $span = max(1, $end - $start);

        $counts = array_fill(0, self::BUCKETS, 0);
        $sums = array_fill(0, self::BUCKETS, 0.0);

        foreach ($traces as $trace) {
            $pos = (int) (($trace->timestampMs - $start) / $span * self::BUCKETS);
            $pos = max(0, min(self::BUCKETS - 1, $pos));
            $counts[$pos]++;
            $sums[$pos] += $trace->durationMs;
        }

        $latency = [];
        foreach ($counts as $i => $n) {
            $latency[] = $n > 0 ? round($sums[$i] / $n, 1) : 0.0;
        }

        return [$counts, $latency];
    }

    /**
     * Slowest endpoints by average latency, with their call count.
     *
     * @param  list<TraceSummary>  $traces
     * @return list<array{name: string, count: int, avgMs: float, maxMs: float}>
     */
    private function endpoints(array $traces): array
    {
        $groups = [];

        foreach ($traces as $trace) {
            $groups[$trace->name] ??= ['sum' => 0.0, 'max' => 0.0, 'count' => 0];
            $groups[$trace->name]['sum'] += $trace->durationMs;
            $groups[$trace->name]['max'] = max($groups[$trace->name]['max'], $trace->durationMs);
            $groups[$trace->name]['count']++;
        }

        $rows = [];
        foreach ($groups as $name => $g) {
            $rows[] = [
                'name' => (string) $name,
                'count' => $g['count'],
                'avgMs' => round($g['sum'] / $g['count'], 1),
                'maxMs' => round($g['max'], 1),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['avgMs'] <=> $a['avgMs']);

        return array_slice($rows, 0, 10);
    }

    /**
     * @param  list<float>  $sorted  ascending
     */
    private function percentile(array $sorted, float $p): float
    {
        if ($sorted === []) {
            return 0.0;
        }

        $index = (int) floor($p * (count($sorted) - 1));

        return round($sorted[$index], 1);
    }
}
