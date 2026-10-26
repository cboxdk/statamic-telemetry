<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Listeners;

use Illuminate\Support\Facades\Gate;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Statamic;

/**
 * Gives every entry blueprint a full-width "Telemetry" tab — the entry's own
 * telemetry story in the editor, the way SEO Pro adds its SEO tab — for
 * users who may view telemetry. The field is `computed`: it is never saved
 * with the entry. Off with `statamic-telemetry.cp.entry_tab = false`.
 */
final class AddTelemetryTab
{
    public const HANDLE = 'telemetry';

    public function handle(EntryBlueprintFound $event): void
    {
        // Only inside the Control Panel: a signed-in editor browsing the site,
        // the REST/GraphQL API or a console command must see the blueprint as
        // everyone else does — no extra `telemetry` key in augmented data.
        if (! config('statamic-telemetry.cp.entry_tab', true) || ! Statamic::isCpRoute() || ! Gate::allows('view telemetry')) {
            return;
        }

        $event->blueprint->ensureFieldInTab(self::HANDLE, [
            'type' => 'telemetry',
            'display' => 'Telemetry',
            'width' => 100,
            'visibility' => 'computed',
            'listable' => false,
            // The tab is the label; a second "Telemetry · Read only" line above the content is noise.
            'hide_display' => true,
        ], self::HANDLE);
    }
}
