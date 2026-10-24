<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Telemetry;

/**
 * The metric-backed data for the Telemetry dashboard, from PromQL over the
 * window: request volume split by response-status class, latency (avg +
 * percentiles) with their over-time series, the routes that need attention
 * (most server errors / slowest first), and the Statamic signals this addon
 * emits — static cache, Stache, Glide, forms and content changes.
 */
final class MetricsOverview
{
    /**
     * @param  array<string, float>  $statusClasses  per class: {1xx,2xx,3xx,4xx,5xx}
     * @param  array{t: list<int>, ok: list<float>, client: list<float>, server: list<float>}  $statusSeries  stacked throughput
     * @param  array{t: list<int>, avg: list<float>, p95: list<float>}  $durationSeries  latency (ms) over time
     * @param  array{requests: ?float, errorRate: ?float, avg: ?float, p95: ?float}  $delta  period-over-period change
     * @param  list<array{route: string, total: float, errors: float, errorRate: float, p95: float, trend: list<float>}>  $routes
     * @param  array<string, float>  $cacheOperations
     * @param  list<array{type: string, action: string, count: float}>  $contentChanges
     * @param  list<array<string, string|float>>  $glide  each {preset, count}
     * @param  list<array<string, string|float>>  $forms  each {form, count}
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
        // Static cache
        public readonly ?float $cacheHitRatio,
        public readonly array $cacheOperations,
        // Stache
        public readonly float $stacheWarms,
        public readonly float $stacheClears,
        public readonly ?float $stacheWarmP95,
        // Glide
        public readonly array $glide,
        public readonly float $glideClears,
        // Forms
        public readonly array $forms,
        // Content
        public readonly array $contentChanges,
    ) {}

    public function hasRequests(): bool
    {
        return $this->requestTotal > 0 || $this->routes !== [];
    }

    public function hasStatamic(): bool
    {
        return $this->cacheOperations !== []
            || $this->stacheWarms > 0 || $this->stacheClears > 0
            || $this->glide !== [] || $this->glideClears > 0
            || $this->forms !== [] || $this->contentChanges !== [];
    }

    public function hasData(): bool
    {
        return $this->hasRequests() || $this->hasStatamic();
    }
}
