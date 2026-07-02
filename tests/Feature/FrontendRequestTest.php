<?php

declare(strict_types=1);

use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

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

test('entries in structured collections are unwrapped from their page object', function () {
    // The default statamic/statamic skeleton's pages collection: structured
    // with a tree — ResponseCreated then carries a Structures\Page wrapper,
    // not the entry itself. Regression from the live demo.
    $collection = Collection::make('docs')->routes('{parent_uri}/{slug}')->structureContents(['root' => true]);
    $collection->save();

    $entry = tap(Entry::make()->collection('docs')->slug('guide')->data(['title' => 'Guide']))->save();

    $collection->structure()->makeTree(Site::default()->handle())
        ->tree([['entry' => $entry->id()]])->save();

    $fake = $this->fakeTelemetry();

    // With root: true the first tree entry is the site root.
    $this->get('/')->assertOk();

    $spans = array_filter(
        $fake->recordedSpans(),
        fn ($span) => str_starts_with($span->name, 'GET entry:docs'),
    );

    expect($spans)->toHaveCount(1);

    $span = array_values($spans)[0];

    expect($span->attributes()['statamic.collection'])->toBe('docs')
        ->and($span->attributes()['statamic.entry.id'])->toBe((string) $entry->id());
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
