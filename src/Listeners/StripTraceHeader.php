<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Listeners;

use Cbox\StatamicTelemetry\StaticCaching\StaticCacheTelemetry;
use Illuminate\Routing\Events\ResponsePrepared;

/**
 * Removes the trace id response header before the half-measure cacher
 * snapshots headers.
 *
 * The ApplicationCacher captures (almost) all response headers via its
 * own ResponsePrepared listener, registered at cachePage() time — replay
 * would then serve one stale trace id to every visitor. This listener is
 * registered at boot, so it runs first; TracingApplicationCacher flags
 * the pending write.
 */
class StripTraceHeader
{
    public function handle(ResponsePrepared $event): void
    {
        if (! StaticCacheTelemetry::consumePendingHeaderStrip()) {
            return;
        }

        $header = config('telemetry.traces.response_header', 'X-Trace-Id');

        if (is_string($header) && $header !== '') {
            $event->response->headers->remove($header);
        }
    }
}
