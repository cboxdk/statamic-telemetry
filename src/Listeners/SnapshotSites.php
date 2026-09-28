<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Listeners;

use Cbox\StatamicTelemetry\Support\ContentAudit;
use Illuminate\Routing\Events\RouteMatched;

/**
 * Takes the sites as they are before the CP saves them. Statamic writes all
 * sites at once and fires `SiteSaved` for every one, with no dirty state to
 * say which changed; the audit record compares against this instead.
 */
class SnapshotSites extends GuardedListener
{
    public const ROUTE = 'statamic.cp.sites.update';

    protected function handleEvent(object $event): void
    {
        if (! $event instanceof RouteMatched || $event->route->getName() !== self::ROUTE) {
            return;
        }

        if (! config('statamic-telemetry.instrument.content_events', true) || ! config('statamic-telemetry.instrument.content_audit', true)) {
            return;
        }

        app(ContentAudit::class)->snapshotSites();
    }
}
