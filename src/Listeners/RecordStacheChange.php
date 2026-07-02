<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Listeners;

use Cbox\Telemetry\Facades\Telemetry;
use Statamic\Events\StacheCleared;
use Statamic\Events\StacheWarmed;
use Statamic\Facades\Stache;
use Throwable;

class RecordStacheChange
{
    public function handle(StacheWarmed|StacheCleared $event): void
    {
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
        try {
            $ms = Stache::buildTime();

            return $ms === null ? null : (float) $ms;
        } catch (Throwable) {
            return null;
        }
    }
}
