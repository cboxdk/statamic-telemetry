<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Listeners;

use Cbox\Telemetry\Facades\Telemetry;
use Statamic\Events\GlideImageGenerated;

class RecordGlideGeneration extends GuardedListener
{
    protected function handleEvent(object $event): void
    {
        if (! $event instanceof GlideImageGenerated || ! config('statamic-telemetry.instrument.glide', true)) {
            return;
        }

        // Preset name is the only bounded dimension; ad-hoc manipulations
        // (raw w/h params) are grouped under "custom".
        $preset = is_array($event->params) ? ($event->params['p'] ?? null) : null;
        $preset = is_string($preset) ? $preset : 'custom';

        Telemetry::counter('statamic.glide.generations', 'Glide images generated')
            ->inc(1, ['preset' => $preset]);

        // A child span on the active request trace, so the individual image
        // (source path + preset) is drillable and correlated to the request
        // that triggered it — not just an aggregate count. The event fires
        // after generation with no start time, so it is a point-in-time marker.
        Telemetry::tracer()->recordSpan('statamic.glide.generate', 0.0, [
            'statamic.glide.preset' => $preset,
            'statamic.glide.path' => is_string($event->path) ? $event->path : null,
        ]);
    }
}
