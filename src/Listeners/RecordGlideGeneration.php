<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Listeners;

use Cbox\Telemetry\Facades\Telemetry;
use Statamic\Events\GlideImageGenerated;

class RecordGlideGeneration
{
    public function handle(GlideImageGenerated $event): void
    {
        if (! config('statamic-telemetry.instrument.glide', true)) {
            return;
        }

        // Preset name is the only bounded dimension; ad-hoc manipulations
        // (raw w/h params) are grouped under "custom".
        $preset = is_array($event->params) ? ($event->params['p'] ?? null) : null;

        Telemetry::counter('statamic.glide.generations', 'Glide images generated')
            ->inc(1, ['preset' => is_string($preset) ? $preset : 'custom']);
    }
}
