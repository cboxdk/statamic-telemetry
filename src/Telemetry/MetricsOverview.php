<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Telemetry;

/**
 * The metric-backed section of the Telemetry page: requests grouped by the
 * addon's bounded content route (`entry:{collection}.{blueprint}` rather than
 * the catch-all `/{segments?}`), the static-cache hit ratio and operations,
 * and content changes by type and action — all from PromQL over the window.
 */
final class MetricsOverview
{
    /**
     * @param  list<array{route: string, count: float}>  $routes
     * @param  array<string, float>  $cacheOperations
     * @param  list<array{type: string, action: string, count: float}>  $contentChanges
     */
    public function __construct(
        public readonly array $routes,
        public readonly ?float $cacheHitRatio,
        public readonly array $cacheOperations,
        public readonly array $contentChanges,
    ) {}

    public function hasData(): bool
    {
        return $this->routes !== [] || $this->cacheOperations !== [] || $this->contentChanges !== [];
    }
}
