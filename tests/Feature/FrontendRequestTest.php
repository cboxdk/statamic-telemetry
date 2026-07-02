<?php

declare(strict_types=1);

use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

test('a frontend entry request gets a bounded span name and entry attributes', function () {
    Collection::make('pages')->routes('{slug}')->save();

    $entry = tap(Entry::make()->collection('pages')->slug('about')->data(['title' => 'About']))->save();

    $fake = $this->fakeTelemetry();

    $this->get('/about')->assertOk();

    $spans = array_filter(
        $fake->recordedSpans(),
        fn ($span) => str_starts_with($span->name, 'GET entry:pages'),
    );

    expect($spans)->toHaveCount(1);

    $span = array_values($spans)[0];

    expect($span->attributes()['statamic.collection'])->toBe('pages')
        ->and($span->attributes()['statamic.entry.id'])->toBe((string) $entry->id())
        ->and($span->attributes()['statamic.type'])->toBe('entry')
        ->and($span->attributes()['http.route'])->not->toBeNull();
});

test('non-statamic routes keep their route pattern name', function () {
    $fake = $this->fakeTelemetry();

    $this->get('/definitely-missing-page')->assertNotFound();

    $named = array_filter(
        $fake->recordedSpans(),
        fn ($span) => str_starts_with($span->name, 'GET entry:'),
    );

    expect($named)->toBeEmpty();
});
