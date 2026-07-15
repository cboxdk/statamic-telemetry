<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Listeners;

use Cbox\Telemetry\Facades\Telemetry;
use Statamic\Events\StacheCleared;
use Statamic\Events\StacheWarmed;
use Statamic\Facades\Stache;

class RecordStacheChange extends GuardedListener
{
    protected function handleEvent(object $event): void
    {
        if (! $event instanceof StacheWarmed && ! $event instanceof StacheCleared) {
            return;
        }

        if (! config('statamic-telemetry.instrument.stache', true)) {
            return;
        }

        if ($event instanceof StacheWarmed) {
            Telemetry::counter('statamic.stache.warms', 'Stache warm operations')->inc();

            $ms = $this->buildTime();

            if ($ms !== null) {
                Telemetry::histogram('statamic.stache.warm_duration', description: 'Stache warm build time', unit: 'ms')
                    ->record($ms);
            }

            // Unlike the other overlays this one has a real duration, so the
            // span reflects the actual build time and shows up as a timed child
            // of the request/command that warmed the Stache.
            Telemetry::tracer()->recordSpan('statamic.stache.warm', $ms ?? 0.0);

            return;
        }

        Telemetry::counter('statamic.stache.clears', 'Stache clear operations')->inc();

        Telemetry::tracer()->recordSpan('statamic.stache.clear', 0.0);
    }

    private function buildTime(): ?float
    {
        // A throw here is caught by the guard after the warm counter has
        // already incremented — the histogram is simply skipped.
        $ms = Stache::buildTime();

        return $ms === null ? null : (float) $ms;
    }
}
