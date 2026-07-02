<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Metrics;

use Cbox\Telemetry\Contracts\TelemetryProvider;
use Cbox\Telemetry\Metrics\Registry;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Statamic\Query\Builder;

/**
 * Pull gauges evaluated at scrape/flush time. Counts go through Stache
 * indexes, but they still query on every scrape — which is why the
 * gauges are opt-in (statamic-telemetry.gauges.enabled).
 */
final class StatamicMetricsProvider implements TelemetryProvider
{
    public function name(): string
    {
        return 'cbox.statamic';
    }

    public function register(Registry $registry): void
    {
        $registry->gauge(
            'statamic.entries.count',
            fn () => Collection::handles()
                ->map(fn (string $handle) => [$this->countEntries($handle), ['collection' => $handle]])
                ->values()
                ->all(),
            'Entries per collection',
        );

        $registry->gauge(
            'statamic.assets.count',
            fn () => AssetContainer::all()
                ->map(fn ($container) => [$this->count($container->queryAssets()), ['container' => (string) $container->handle()]])
                ->values()
                ->all(),
            'Assets per container',
        );

        $registry->gauge(
            'statamic.users.count',
            fn () => User::count(),
            'Statamic users',
        );
    }

    private function countEntries(string $collection): int
    {
        $query = Entry::query();

        if ($query instanceof Builder) {
            $query->where('collection', $collection);
        }

        return $this->count($query);
    }

    /**
     * The query builder contracts are empty marker interfaces; both the
     * Stache and Eloquent builders extend Statamic\Query\Builder, which
     * carries the actual query API.
     */
    private function count(mixed $query): int
    {
        return $query instanceof Builder ? (int) $query->count() : 0;
    }
}
