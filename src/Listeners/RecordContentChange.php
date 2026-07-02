<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Listeners;

use Cbox\Telemetry\Facades\Telemetry;
use Illuminate\Support\Str;

/**
 * One counter for all content mutations, labelled by type and action —
 * the event class name carries both (EntrySaved → entry/saved,
 * AssetUploaded → asset/uploaded). Both dimensions are bounded by the
 * fixed set of events the service provider subscribes to.
 */
class RecordContentChange
{
    public function handle(object $event): void
    {
        if (! config('statamic-telemetry.instrument.content_events', true)) {
            return;
        }

        if (! preg_match('/^(.+?)(Saved|Deleted|Uploaded)$/', class_basename($event), $matches)) {
            return;
        }

        Telemetry::counter('statamic.content.changes', 'Content changes by type and action')
            ->inc(1, ['type' => Str::snake($matches[1]), 'action' => strtolower($matches[2])]);
    }
}
