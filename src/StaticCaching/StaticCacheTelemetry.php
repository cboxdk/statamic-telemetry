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
 *
 * Outcome recording is gated to the request currently being served
 * (isCurrentRequest). Statamic checks the cache against freshly built,
 * synthetic Request objects too — error-page copies, warm jobs — and
 * those must not inflate the hit/miss counters or overwrite the outcome
 * on someone else's root span.
 */
final class StaticCacheTelemetry
{
    public const RESULT_ATTRIBUTE = 'statamic.static_cache';

    private const RECORDED_KEY = 'statamic_telemetry_static_cache_result';

    private const PENDING_STRIP_KEY = 'statamic_telemetry_strip_trace_header';

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

    /**
     * Flags the current request's response so the trace id header is
     * stripped before the half-measure cacher snapshots it. Stored on the
     * request (not a static) so a request that sets it but never emits a
     * ResponsePrepared can't leak the flag into the next Octane request.
     */
    public static function markPendingHeaderStrip(Request $request): void
    {
        $request->attributes->set(self::PENDING_STRIP_KEY, true);
    }

    public static function consumePendingHeaderStrip(Request $request): bool
    {
        $pending = (bool) $request->attributes->get(self::PENDING_STRIP_KEY, false);

        $request->attributes->set(self::PENDING_STRIP_KEY, false);

        return $pending;
    }

    private static function recordOutcome(string $result, Request $request): void
    {
        if (! self::enabled() || ! self::isCurrentRequest($request)) {
            return;
        }

        // hasCachedPage() can run more than once per request (middleware,
        // nocache), and the write lands between two of those probes — so
        // remember every outcome already counted, not just the last one. A
        // single slot let the sequence the comment itself describes,
        // miss -> write -> miss, count the miss twice: one page serve
        // reported as two, which is a hit ratio that lies.
        $recorded = $request->attributes->get(self::RECORDED_KEY);
        $recorded = is_array($recorded) ? $recorded : [];

        if (isset($recorded[$result])) {
            return;
        }

        $recorded[$result] = true;

        $request->attributes->set(self::RECORDED_KEY, $recorded);

        self::operations()->inc(1, ['operation' => $result]);

        Telemetry::tracer()->rootSpan()?->setAttribute(self::RESULT_ATTRIBUTE, $result);
    }

    /**
     * Is this the request actually being served, versus a synthetic one
     * Statamic built to probe the cache (error copies, warm jobs)?
     */
    private static function isCurrentRequest(Request $request): bool
    {
        return app()->bound('request') && app('request') === $request;
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
