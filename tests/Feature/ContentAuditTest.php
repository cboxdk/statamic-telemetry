<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Support\ContentAudit;
use Cbox\Telemetry\Testing\TelemetryFake;
use Illuminate\Support\Facades\Cache;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;

beforeEach(function () {
    // Hand out copies of cached Stache items, as the file and redis stores
    // do in production. The array store's default hands back the cached
    // instance itself, whose original Statamic re-syncs on every lookup.
    config()->set('cache.stores.array.serialize', true);
    Cache::forgetDriver('array');

    Collection::make('pages')->title('Pages')->save();
});

/**
 * @return list<array<string, scalar|null>>
 */
function auditRecords(TelemetryFake $fake): array
{
    return array_values(array_map(
        static fn ($event): array => $event->attributes,
        $fake->recordedEvents(ContentAudit::EVENT),
    ));
}

test('a new entry is one created record, with what an editor recognises it by', function () {
    $fake = $this->fakeTelemetry();

    Entry::make()->collection('pages')->slug('about')->data(['title' => 'About us'])->published(true)->save();

    $records = auditRecords($fake);

    expect($records)->toHaveCount(1)
        ->and($records[0])->toMatchArray([
            'statamic.content.type' => 'entry',
            'statamic.content.action' => 'created',
            'statamic.content.title' => 'About us',
            'statamic.collection' => 'pages',
            'statamic.site' => 'default',
            'statamic.content.slug' => 'about',
            'statamic.content.status' => 'published',
            'statamic.content.origin' => 'console',
        ])
        ->and($records[0]['statamic.content.id'] ?? null)->toBeString()
        ->and($records[0]['statamic.content.edit_url'] ?? '')->toContain('/cp/collections/pages/entries/');

    // The creation is counted once too: EntryCreated only marks, EntrySaved counts.
    expect($fake->recordedMetrics('statamic.content.changes'))->toHaveCount(1);
});

test('an update names the fields that changed, not their values', function () {
    $entry = tap(Entry::make()->collection('pages')->slug('a')->data(['title' => 'A', 'intro' => 'old']))->save();
    $entry = Entry::find($entry->id());

    $fake = $this->fakeTelemetry();

    $entry->set('intro', 'new secret text')->set('title', 'A2')->save();

    $record = auditRecords($fake)[0];

    expect($record['statamic.content.action'])->toBe('updated')
        ->and($record['statamic.content.changed'])->toBe('title, intro')
        ->and(implode(' ', array_map('strval', $record)))->not->toContain('new secret text');
});

test('publishing and unpublishing are transitions, not saves', function () {
    $entry = tap(Entry::make()->collection('pages')->slug('b')->published(false))->save();
    $entry = Entry::find($entry->id());

    $fake = $this->fakeTelemetry();

    $entry->published(true)->save();
    $entry = Entry::find($entry->id());
    $entry->published(false)->save();

    expect(array_column(auditRecords($fake), 'statamic.content.action'))->toBe(['published', 'unpublished']);
});

test('a save that changed nothing says so — it is still a row', function () {
    $entry = tap(Entry::make()->collection('pages')->slug('c')->data(['title' => 'C']))->save();
    $entry = Entry::find($entry->id());

    $fake = $this->fakeTelemetry();

    $entry->save();

    expect(auditRecords($fake)[0]['statamic.content.action'])->toBe('saved')
        ->and(auditRecords($fake)[0])->not->toHaveKey('statamic.content.changed');
    // The counter keeps counting saves as it always has.
    $fake->assertCounterIncremented('statamic.content.changes', ['type' => 'entry', 'action' => 'published']);
});

test('localisations saved only because their origin was are not rows', function () {
    config()->set('statamic.system.multisite', true);
    Site::setSites([
        'en' => ['name' => 'English', 'url' => '/', 'locale' => 'en_US'],
        'da' => ['name' => 'Danish', 'url' => '/da/', 'locale' => 'da_DK'],
    ]);
    Collection::make('blog')->sites(['en', 'da'])->propagate(true)->save();

    $fake = $this->fakeTelemetry();

    // Created in English, propagated to Danish by Statamic.
    $entry = tap(Entry::make()->collection('blog')->locale('en')->slug('x')->data(['title' => 'Origin']))->save();
    $entry = Entry::find($entry->id());
    // Updating the origin re-saves the Danish localisation too.
    $entry->set('title', 'Origin 2')->save();

    // Both saves reached the Danish copy (the spans say so) — neither was an edit of it.
    expect(count($fake->recordedSpans(ContentAudit::EVENT)))->toBe(4)
        ->and(array_map(
            static fn (array $r): string => $r['statamic.content.action'].' '.$r['statamic.site'],
            auditRecords($fake),
        ))->toBe(['created en', 'updated en']);
});

test('a deletion is recorded with the item it removed', function () {
    $entry = tap(Entry::make()->collection('pages')->slug('d')->data(['title' => 'Doomed']))->save();

    $fake = $this->fakeTelemetry();

    $entry->delete();

    expect(auditRecords($fake)[0])->toMatchArray([
        'statamic.content.action' => 'deleted',
        'statamic.content.title' => 'Doomed',
        'statamic.content.id' => $entry->id(),
    ]);
});

test('structure changes are recorded too: a collection created, then updated', function () {
    $fake = $this->fakeTelemetry();

    $collection = tap(Collection::make('news')->title('News'))->save();
    $collection->title('Newsroom')->save();

    expect(array_map(
        static fn (array $r): array => [$r['statamic.content.type'], $r['statamic.content.action'], $r['statamic.content.handle'] ?? null],
        auditRecords($fake),
    ))->toBe([
        ['collection', 'created', 'news'],
        ['collection', 'updated', 'news'],
    ]);
});

test('the signed-in editor is the actor — id and name, never the email', function () {
    $user = tap(User::make()->email('editor@example.com')->data(['name' => 'Ed Itor']))->save();
    $this->actingAs($user);

    $fake = $this->fakeTelemetry();

    Entry::make()->collection('pages')->slug('e')->save();

    $record = auditRecords($fake)[0];

    expect($record['user.id'])->toBe((string) $user->id())
        ->and($record['user.name'])->toBe('Ed Itor')
        ->and(implode(' ', array_map('strval', $record)))->not->toContain('editor@example.com');
});

test('the actor name can be switched off, leaving the id', function () {
    config()->set('statamic-telemetry.audit.actor_name', false);
    $user = tap(User::make()->email('editor@example.com')->data(['name' => 'Ed Itor']))->save();
    $this->actingAs($user);

    $fake = $this->fakeTelemetry();

    Entry::make()->collection('pages')->slug('f')->save();

    expect(auditRecords($fake)[0])->toHaveKey('user.id')->not->toHaveKey('user.name');
});

test('the audit record can be switched off without losing the counter', function () {
    config()->set('statamic-telemetry.instrument.content_audit', false);

    $fake = $this->fakeTelemetry();

    Entry::make()->collection('pages')->slug('g')->save();

    $fake->assertEventNotEmitted(ContentAudit::EVENT);
    $fake->assertCounterIncremented('statamic.content.changes', ['type' => 'entry', 'action' => 'published']);
});

test('the change span carries the same detail as the record', function () {
    $fake = $this->fakeTelemetry();

    Entry::make()->collection('pages')->slug('h')->data(['title' => 'Spanned'])->save();

    $fake->assertSpanRecorded(
        ContentAudit::EVENT,
        fn ($span) => ($span->attributes()['statamic.content.title'] ?? null) === 'Spanned'
            && ($span->attributes()['statamic.content.action'] ?? null) === 'created',
    );
});
