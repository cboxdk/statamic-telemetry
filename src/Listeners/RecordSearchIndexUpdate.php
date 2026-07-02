<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Listeners;

use Cbox\Telemetry\Facades\Telemetry;
use Statamic\Events\SearchIndexUpdated;
use Throwable;

/**
 * Index updates only — Statamic fires no event for search queries, so
 * query latency is visible through the request span, not a counter.
 */
class RecordSearchIndexUpdate
{
    public function handle(SearchIndexUpdated $event): void
    {
        if (! config('statamic-telemetry.instrument.search', true)) {
            return;
        }

        try {
            $index = (string) $event->index->name();
        } catch (Throwable) {
            $index = 'unknown';
        }

        Telemetry::counter('statamic.search.index_updates', 'Search index updates')
            ->inc(1, ['index' => $index]);
    }
}
