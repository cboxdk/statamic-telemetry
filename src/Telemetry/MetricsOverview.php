<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Telemetry;

/**
 * The metric-backed data for the Telemetry dashboard, from PromQL over the
 * window: request volume split by response-status class, latency (avg +
 * percentiles) with their over-time series, the routes that need attention
 * (most server errors / slowest first). The Statamic signals have their own
 * pages (see TelemetryPages) and are no longer carried here.
 */
final class MetricsOverview
{
    /**
     * @param  array<string, float>  $statusClasses  per class: {1xx,2xx,3xx,4xx,5xx}
     * @param  array{t: list<int>, ok: list<float>, client: list<float>, server: list<float>}  $statusSeries  stacked throughput
     * @param  array{t: list<int>, avg: list<float>, p95: list<float>}  $durationSeries  latency (ms) over time
     * @param  array{requests: ?float, errorRate: ?float, avg: ?float, p95: ?float}  $delta  period-over-period change
     * @param  list<array{route: string, total: float, errors: float, errorRate: float, p95: float, trend: list<float>}>  $routes
     */
    public function __construct(
        // Requests
        public readonly float $requestTotal,
        public readonly float $errorRate,
        public readonly array $statusClasses,
        public readonly array $statusSeries,
        // Latency
        public readonly float $avg,
        public readonly float $p50,
        public readonly float $p95,
        public readonly float $p99,
        public readonly array $durationSeries,
        public readonly array $delta,
        // Routes needing attention
        public readonly array $routes,
    ) {}

    public function hasRequests(): bool
    {
        return $this->requestTotal > 0 || $this->routes !== [];
    }

    public function hasData(): bool
    {
        return $this->hasRequests();
    }
}
