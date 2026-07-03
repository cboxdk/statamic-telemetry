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

            if (($ms = $this->buildTime()) !== null) {
                Telemetry::histogram('statamic.stache.warm_duration', description: 'Stache warm build time', unit: 'ms')
                    ->record($ms);
            }

            return;
        }

        Telemetry::counter('statamic.stache.clears', 'Stache clear operations')->inc();
    }

    private function buildTime(): ?float
    {
        // A throw here is caught by the guard after the warm counter has
        // already incremented — the histogram is simply skipped.
        $ms = Stache::buildTime();

        return $ms === null ? null : (float) $ms;
    }
}
