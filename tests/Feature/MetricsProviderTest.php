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

    $families = collect($fake->collect());

    $entries = $families->first(fn ($family) => $family->name() === 'statamic.entries.count');

    expect($entries)->not->toBeNull()
        ->and($entries->samples[0]->labels)->toBe(['collection' => 'pages'])
        ->and($entries->samples[0]->value)->toBe(2.0);

    expect($families->first(fn ($family) => $family->name() === 'statamic.users.count'))->not->toBeNull();
});
