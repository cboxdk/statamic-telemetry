<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\StaticCaching;

use Illuminate\Http\Request;
use Statamic\StaticCaching\Cachers\ApplicationCacher;

final class TracingApplicationCacher extends ApplicationCacher
{
    use RecordsStaticCacheTelemetry;

    public function cachePage(Request $request, $content)
    {
        // Before parent — its ResponsePrepared listener snapshots response
        // headers into the cache, and the trace id header must be gone by
        // then (see StripTraceHeader).
        StaticCacheTelemetry::markPendingHeaderStrip($request);

        parent::cachePage($request, $content);

        StaticCacheTelemetry::recordWrite($request);
    }
}
