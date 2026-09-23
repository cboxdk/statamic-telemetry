<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Metrics\StatamicMetricsProvider;
use Cbox\Telemetry\Facades\Telemetry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

test('gauges report entries per collection at collect time', function () {
    $fake = $this->fakeTelemetry();

    Telemetry::provider(new StatamicMetricsProvider);

    Collection::make('pages')->save();
    Entry::make()->collection('pages')->slug('one')->save();
    Entry::make()->collection('pages')->slug('two')->save();

    // One series per collection. Reading samples[0] could not see a
    // duplicate `pages` series or a stray extra collection — either of
    // which doubles the entry total on a dashboard.
    $entries = $fake->recordedMetrics('statamic.entries.count')
        ->assertLabelValues('collection', ['pages'])
        ->assertSeriesCount(1);

    expect($entries->total())->toBe(2.0);

    expect($fake->recordedMetrics('statamic.users.count'))->not->toBeEmpty();
});
