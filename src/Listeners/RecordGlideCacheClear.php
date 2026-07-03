<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Listeners;

use Cbox\Telemetry\Facades\Telemetry;
use Statamic\Events\GlideAssetCacheCleared;
use Statamic\Events\GlideCacheCleared;

/**
 * Glide cache clears explain generation spikes: after a full clear,
 * every image regenerates on first request.
 */
class RecordGlideCacheClear extends GuardedListener
{
    protected function handleEvent(object $event): void
    {
        if (! $event instanceof GlideCacheCleared && ! $event instanceof GlideAssetCacheCleared) {
            return;
        }

        if (! config('statamic-telemetry.instrument.glide', true)) {
            return;
        }

        Telemetry::counter('statamic.glide.cache_clears', 'Glide cache clears')
            ->inc(1, ['scope' => $event instanceof GlideAssetCacheCleared ? 'asset' : 'all']);
    }
}
