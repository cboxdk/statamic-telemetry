<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\StaticCaching;

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Metrics\Instruments\Counter;
use Illuminate\Http\Request;

/**
 * Shared recording logic for the tracing cachers: an operations counter,
 * a hit/miss/write outcome attribute on the request root span, and the
 * pending-write flag the StripTraceHeader listener consumes.
 */
final class StaticCacheTelemetry
{
    public const RESULT_ATTRIBUTE = 'statamic.static_cache';

    private const RECORDED_KEY = 'statamic_telemetry_static_cache_result';

    /**
     * Set by TracingApplicationCacher::cachePage(), consumed by the
     * boot-registered ResponsePrepared listener — which therefore runs
     * before the cacher's own header-snapshotting listener.
     */
    private static bool $pendingHeaderStrip = false;

    public static function recordHit(Request $request): void
    {
        self::recordOutcome('hit', $request);
    }

    public static function recordMiss(Request $request): void
    {
        self::recordOutcome('miss', $request);
    }

    /**
     * A write follows a miss in the same request; the span attribute is
     * overwritten so the final value tells the fuller story.
     */
    public static function recordWrite(Request $request): void
    {
        self::recordOutcome('write', $request);
    }

    public static function recordInvalidation(): void
    {
        if (self::enabled()) {
            self::operations()->inc(1, ['operation' => 'invalidate']);
        }
    }

    public static function recordFlush(): void
    {
        if (self::enabled()) {
            self::operations()->inc(1, ['operation' => 'flush']);
        }
    }

    public static function markPendingHeaderStrip(): void
    {
        self::$pendingHeaderStrip = true;
    }

    public static function consumePendingHeaderStrip(): bool
    {
        $pending = self::$pendingHeaderStrip;
        self::$pendingHeaderStrip = false;

        return $pending;
    }

    private static function recordOutcome(string $result, Request $request): void
    {
        if (! self::enabled()) {
            return;
        }

        // hasCachedPage() can run more than once per request (middleware,
        // nocache) — count each outcome once per request.
        if ($request->attributes->get(self::RECORDED_KEY) === $result) {
            return;
        }

        $request->attributes->set(self::RECORDED_KEY, $result);

        self::operations()->inc(1, ['operation' => $result]);

        Telemetry::tracer()->rootSpan()?->setAttribute(self::RESULT_ATTRIBUTE, $result);
    }

    private static function enabled(): bool
    {
        return config('statamic-telemetry.enabled', true)
            && config('statamic-telemetry.instrument.static_cache', true);
    }

    private static function operations(): Counter
    {
        return Telemetry::counter('statamic.static_cache.operations', 'Static cache operations by outcome');
    }
}
