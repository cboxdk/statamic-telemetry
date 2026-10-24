<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Telemetry;

/**
 * The Exceptions card's data for the dashboard: how many error-level log lines
 * the backend holds over the window, a throughput sparkline bucketed from their
 * timestamps, and the most recent few. Derived from {@see Logs} (Loki
 * `level="error"`) — the same stream the Explore "Errors" signal lists.
 */
final class Exceptions
{
    private const BUCKETS = 32;

    private const LIMIT = 1000;

    public function __construct(private readonly Logs $logs) {}

    /**
     * @return array{count: int, capped: bool, trend: list<int>, recent: list<array{timestamp: int, line: string}>}
     */
    public function overview(int $windowSeconds, ?int $endsAt = null): array
    {
        $rows = $this->logs->query($windowSeconds, 'error', '', self::LIMIT, $endsAt);

        return [
            'count' => count($rows),
            'capped' => count($rows) >= self::LIMIT,
            'trend' => $this->trend($rows, $windowSeconds, $endsAt),
            'recent' => array_map(
                static fn (array $r): array => ['timestamp' => $r['timestamp'], 'line' => $r['line']],
                array_slice($rows, 0, 5),
            ),
        ];
    }

    /**
     * Bucket error timestamps (ms) across the window into a small sparkline.
     *
     * @param  list<array{timestamp: int, level: string, line: string, labels: array<string, string>, traceId: ?string}>  $rows
     * @return list<int>
     */
    private function trend(array $rows, int $windowSeconds, ?int $endsAt = null): array
    {
        if ($rows === []) {
            return [];
        }

        $end = ($endsAt ?? time()) * 1000;
        $start = $end - $windowSeconds * 1000;
        $span = max(1, $end - $start);

        $counts = array_fill(0, self::BUCKETS, 0);

        foreach ($rows as $row) {
            $pos = (int) (($row['timestamp'] - $start) / $span * self::BUCKETS);
            $counts[max(0, min(self::BUCKETS - 1, $pos))]++;
        }

        return $counts;
    }
}
