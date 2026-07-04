<?php

declare(strict_types=1);

use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

beforeEach(function () {
    Collection::make('pages')->save();
});

test('a published entry save is labelled published', function () {
    $fake = $this->fakeTelemetry();

    Entry::make()->collection('pages')->slug('a')->published(true)->save();

    $fake->assertCounterIncremented('statamic.content.changes', ['type' => 'entry', 'action' => 'published']);
});

test('a draft entry save is labelled draft', function () {
    $fake = $this->fakeTelemetry();

    Entry::make()->collection('pages')->slug('b')->published(false)->save();

    $fake->assertCounterIncremented('statamic.content.changes', ['type' => 'entry', 'action' => 'draft']);
});

test('a scheduled entry save is labelled scheduled', function () {
    Collection::make('news')->dated(true)->futureDateBehavior('private')->save();

    $fake = $this->fakeTelemetry();

    Entry::make()->collection('news')->slug('c')
        ->published(true)
        ->date(now()->addWeek()->format('Y-m-d-Hi'))
        ->save();

    $fake->assertCounterIncremented('statamic.content.changes', ['type' => 'entry', 'action' => 'scheduled']);
});

test('a deleted entry is still labelled deleted', function () {
    $entry = tap(Entry::make()->collection('pages')->slug('d')->published(true))->save();

    $fake = $this->fakeTelemetry();

    $entry->delete();

    $fake->assertCounterIncremented('statamic.content.changes', ['type' => 'entry', 'action' => 'deleted']);
});

test('non-entry content keeps its generic saved/deleted action', function () {
    $fake = $this->fakeTelemetry();

    Collection::make('news')->save();

    $fake->assertCounterIncremented('statamic.content.changes', ['type' => 'collection', 'action' => 'saved']);
});
