<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Listeners;

use Cbox\Telemetry\Facades\Telemetry;
use Statamic\Events\StacheCleared;
use Statamic\Events\StacheWarmed;
use Statamic\Facades\Stache;

class RecordStacheChange extends GuardedListener
{
    /** @var list<float> seconds */
    public const BUCKETS = [0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0, 30.0, 60.0, 120.0, 300.0];

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
                // Seconds, per the semantic conventions, like every other
                // duration the stack emits. Its own buckets: a warm takes
                // seconds to minutes, and a host config published before 3.0
                // still carries millisecond `default_buckets` (1…10000),
                // which would put every warm in the first two buckets.
                Telemetry::histogram('statamic.stache.warm_duration', self::BUCKETS, 'Stache warm build time', 's')
                    ->record($ms / 1000);
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
