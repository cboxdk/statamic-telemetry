<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Telemetry;

/**
 * The metric-backed data for the Telemetry page, from PromQL over the window:
 * request volume and histogram latency percentiles, requests grouped by the
 * addon's bounded content route, and the Statamic signals this addon emits —
 * static cache, Stache, Glide, forms and content changes.
 */
final class MetricsOverview
{
    /**
     * @param  list<array<string, string|float>>  $routes  each {route, count}
     * @param  array<string, float>  $cacheOperations
     * @param  list<array{type: string, action: string, count: float}>  $contentChanges
     * @param  list<array<string, string|float>>  $glide  each {preset, count}
     * @param  list<array<string, string|float>>  $forms  each {form, count}
     */
    public function __construct(
        // Requests
        public readonly float $requestTotal,
        public readonly float $p50,
        public readonly float $p95,
        public readonly float $p99,
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
