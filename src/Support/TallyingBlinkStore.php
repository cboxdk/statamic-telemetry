<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Support;

use Cbox\Telemetry\Facades\Telemetry;
use Spatie\Blink\Blink as SpatieBlink;

/**
 * Counts once() outcomes — the get-or-compute path Statamic leans on
 * for augmentation and lookup memoization. A plain per-trace tally via
 * bumpStat: two array operations per call, no spans, no per-key labels.
 */
class TallyingBlinkStore extends SpatieBlink
{
    public function once($key, callable $callable)
    {
        Telemetry::tracer()->bumpStat(
            $this->has($key) ? 'statamic.blink.hits' : 'statamic.blink.misses',
            1,
        );

        return parent::once($key, $callable);
    }
}
