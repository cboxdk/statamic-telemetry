<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Listeners;

use Cbox\Telemetry\Facades\Telemetry;
use Statamic\Events\GlideAssetCacheCleared;
use Statamic\Events\GlideCacheCleared;
use Statamic\Events\StacheCleared;
use Statamic\Events\StaticCacheCleared;

/**
 * Cache purges explain slow requests after the fact: a stache clear means
 * subsequent requests rebuild indexes, a static cache clear turns cached
 * pages back into full renders, a glide clear regenerates every image on
 * first view. Each purge is emitted as an unsampled `statamic.cache.purge`
 * event, which the bundled dashboard renders as an annotation line —
 * latency spikes map to purges at a glance, the same way they map to
 * deploys.
 */
class RecordCachePurge extends GuardedListener
{
    protected function handleEvent(object $event): void
    {
        if (! config('statamic-telemetry.instrument.cache_purges', true)) {
            return;
        }

        $type = match ($event::class) {
            StacheCleared::class => 'stache',
            StaticCacheCleared::class => 'static',
            GlideCacheCleared::class => 'glide',
            GlideAssetCacheCleared::class => 'glide_asset',
            default => null,
        };

        if ($type === null) {
            return;
        }

        // Unsampled — a purge marker must never be dropped by trace
        // sampling; the dashboard renders it as an annotation line.
        Telemetry::event('statamic.cache.purge', [
            'cache.type' => $type,
            'cache.trigger' => app()->runningInConsole() ? 'cli' : 'http',
        ]);
    }
}
