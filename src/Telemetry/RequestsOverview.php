<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Telemetry;

/**
 * The Requests section's computed overview: volume, latency percentiles, the
 * per-bucket series for the charts, the slowest endpoints and the most recent
 * traces. All derived in PHP from the trace list the backend returned.
 */
final class RequestsOverview
{
    /**
     * @param  list<int>  $countSeries  requests per time bucket
     * @param  list<float>  $latencySeries  avg latency (ms) per time bucket
     * @param  list<array{name: string, count: int, avgMs: float, maxMs: float}>  $endpoints
     * @param  list<TraceSummary>  $recent
     */
    public function __construct(
        public readonly int $count,
        public readonly float $perMinute,
        public readonly float $p50,
        public readonly float $p95,
        public readonly float $maxMs,
        public readonly array $countSeries,
        public readonly array $latencySeries,
        public readonly array $endpoints,
        public readonly array $recent,
    ) {}

    public function isEmpty(): bool
    {
        return $this->count === 0;
    }
}
