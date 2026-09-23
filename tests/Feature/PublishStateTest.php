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

test('the publish-state vocabulary is complete and mutually exclusive in one run', function () {
    // The per-state tests above each save one entry, so they cannot see a
    // status that is resolved once and reused, or a state that quietly
    // collapses into a neighbour: every one of them still passes when all
    // four saves land on the same action. Saving all four in one run and
    // pinning the observed set does catch that — and covers `expired`,
    // which the snapshot supports but no single-state test exercises.
    Collection::make('news')->dated(true)
        ->futureDateBehavior('private')
        ->pastDateBehavior('private')
        ->save();

    $fake = $this->fakeTelemetry();

    Entry::make()->collection('pages')->slug('e')->published(true)->save();
    Entry::make()->collection('pages')->slug('f')->published(false)->save();
    Entry::make()->collection('news')->slug('g')->published(true)
        ->date(now()->addWeek()->format('Y-m-d-Hi'))->save();
    Entry::make()->collection('news')->slug('h')->published(true)
        ->date(now()->subWeek()->format('Y-m-d-Hi'))->save();

    $fake->recordedMetrics('statamic.content.changes')
        ->assertLabelValues('action', ['published', 'draft', 'scheduled', 'expired'])
        // Entry saves never fall back to the generic `entry/saved` pair,
        // and nothing else got attributed to this counter.
        ->assertLabelValues('type', ['entry'])
        ->assertSeriesCount(4);
});
