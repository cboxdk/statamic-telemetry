<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Listeners;

use Cbox\Telemetry\Facades\Telemetry;
use Illuminate\Routing\Events\RouteMatched;
use Statamic\Facades\Site;

/**
 * Adds the current site as an ambient dimension once routing has
 * resolved it. Context attributes land on every span in the trace and
 * propagate to queued jobs via the job payload.
 */
class AddSiteContext
{
    public function handle(RouteMatched $event): void
    {
        if (! config('statamic-telemetry.instrument.site_context', true)) {
            return;
        }

        Telemetry::context(['statamic.site' => Site::current()->handle()]);
    }
}
