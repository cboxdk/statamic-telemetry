<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Telemetry;

/**
 * Builds a latency × time heatmap from the trace window — the view telemetry-ui
 * shows beside the Explore throughput chart. Each column is a slice of the
 * window, each row a latency band; the cell is how many requests fell in that
 * band during that slice, so a creeping p95 shows up as the heat climbing into
 * the slower rows. Guarded via {@see Traces}; an empty window returns empties.
 */
final class LatencyHeatmap
{
    private const COLS = 40;

    private const FETCH = 2000;

    /** Band upper bounds in ms (null = “and up”), slowest last. */
    private const BANDS = [
        ['<50ms', 50.0],
        ['50–100ms', 100.0],
        ['100–250ms', 250.0],
        ['250–500ms', 500.0],
        ['500ms–1s', 1000.0],
        ['1–2.5s', 2500.0],
        ['>2.5s', null],
    ];

    public function __construct(private readonly Traces $traces) {}

    /**
     * @return array{cols: list<int>, bands: list<string>, cells: list<array{0: int, 1: int, 2: int}>, max: int}
     */
    public function build(int $windowSeconds, ?string $traceql = null, ?int $endsAt = null): array
    {
        $traces = $this->traces->search($windowSeconds, self::FETCH, $traceql, $endsAt);

        $bands = array_map(static fn (array $b): string => $b[0], self::BANDS);

        if ($traces === []) {
            return ['cols' => [], 'bands' => $bands, 'cells' => [], 'max' => 0];
        }

        $end = ($endsAt ?? time()) * 1000;
        $start = $end - $windowSeconds * 1000;
        $span = max(1, $end - $start);

        // grid[col][band] = count
        $grid = [];
        foreach ($traces as $trace) {
            $col = (int) (($trace->timestampMs - $start) / $span * self::COLS);
            $col = max(0, min(self::COLS - 1, $col));
            $band = $this->bandOf($trace->durationMs);
            $grid[$col][$band] = ($grid[$col][$band] ?? 0) + 1;
        }

        $cells = [];
        $max = 0;
        foreach ($grid as $col => $byBand) {
            foreach ($byBand as $band => $n) {
                $cells[] = [$col, $band, $n];
                $max = max($max, $n);
            }
        }

        $cols = [];
        for ($i = 0; $i < self::COLS; $i++) {
            $cols[] = (int) ($start + ($i + 0.5) / self::COLS * $span);
        }

        return ['cols' => $cols, 'bands' => $bands, 'cells' => $cells, 'max' => $max];
    }

    private function bandOf(float $durationMs): int
    {
        foreach (self::BANDS as $i => [, $upper]) {
            if ($upper === null || $durationMs < $upper) {
                return $i;
            }
        }

        return count(self::BANDS) - 1;
    }
}
