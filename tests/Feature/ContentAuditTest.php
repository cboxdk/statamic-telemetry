<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Listeners\SnapshotSites;
use Cbox\StatamicTelemetry\Support\ContentAudit;
use Cbox\Telemetry\Testing\TelemetryFake;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Route;
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

test('a user as the changed item is known by name, never by email', function () {
    $fake = $this->fakeTelemetry();

    tap(User::make()->email('ed@example.com')->data(['name' => 'Ed Itor']))->save();
    tap(User::make()->email('noname@example.com'))->save();

    $records = auditRecords($fake);

    expect(array_column($records, 'statamic.content.title'))->toBe(['Ed Itor'])
        ->and(json_encode($records))->not->toContain('example.com');
});

test('an editor without a name is their id, not their email', function () {
    $this->actingAs(tap(User::make()->email('anon@example.com'))->save());

    $fake = $this->fakeTelemetry();

    Entry::make()->collection('pages')->slug('n')->save();

    expect(auditRecords($fake)[0])->toHaveKey('user.id')->not->toHaveKey('user.name')
        ->and(json_encode(auditRecords($fake)))->not->toContain('example.com');
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

describe('sites', function () {
    beforeEach(function () {
        config()->set('statamic.system.multisite', true);
        Site::setSites([
            'en' => ['name' => 'English', 'url' => '/', 'locale' => 'en_US'],
            'da' => ['name' => 'Danish', 'url' => '/da/', 'locale' => 'da_DK'],
        ]);
        $this->sitesFile = Site::path();
        $this->hadSitesFile = file_exists($this->sitesFile);
        $this->sitesBackup = $this->hadSitesFile ? file_get_contents($this->sitesFile) : null;
        Site::save();
    });

    afterEach(function () {
        $this->hadSitesFile ? file_put_contents($this->sitesFile, $this->sitesBackup) : @unlink($this->sitesFile);
    });

    test('a CP save of the sites records only the sites that changed, and what changed', function () {
        $fake = $this->fakeTelemetry();

        // What the listener does when the CP's sites route matches.
        app(ContentAudit::class)->snapshotSites();

        Site::setSites([
            'en' => ['name' => 'English', 'url' => '/', 'locale' => 'en_US'],
            'da' => ['name' => 'Dansk', 'url' => 'https://example.dk/', 'locale' => 'da_DK'],
            'de' => ['name' => 'Deutsch', 'url' => '/de/', 'locale' => 'de_DE'],
        ])->save();

        expect(array_map(
            static fn (array $r): array => [$r['statamic.content.action'], $r['statamic.site'], $r['statamic.content.changed'] ?? null],
            auditRecords($fake),
        ))->toEqualCanonicalizing([
            ['created', 'de', null],
            ['updated', 'da', 'name, url'],
        ]);
    });

    test('a removed site is recorded as deleted', function () {
        $fake = $this->fakeTelemetry();
        app(ContentAudit::class)->snapshotSites();

        Site::setSites(['en' => ['name' => 'English', 'url' => '/', 'locale' => 'en_US']])->save();

        expect(array_column(auditRecords($fake), 'statamic.content.action', 'statamic.site'))->toBe(['da' => 'deleted']);
    });

    test('a save from code, with nothing to compare against, is one row for the batch', function () {
        $fake = $this->fakeTelemetry();

        Site::setSites([
            'en' => ['name' => 'English', 'url' => '/', 'locale' => 'en_US'],
            'da' => ['name' => 'Dansk', 'url' => '/da/', 'locale' => 'da_DK'],
        ])->save();

        expect(array_column(auditRecords($fake), 'statamic.content.action'))->toBe(['saved']);
    });

    test('the snapshot is taken when the CP route that saves the sites matches, not on any other route', function () {
        $audit = app(ContentAudit::class);
        $snapshot = fn () => (fn () => $this->sitesBefore)->call($audit);

        $route = fn (string $name) => (new Route('PATCH', 'cp/sites', fn () => null))->name($name);

        event(new RouteMatched($route('statamic.cp.entries.update'), request()));
        expect($snapshot())->toBeNull();

        event(new RouteMatched($route(SnapshotSites::ROUTE), request()));
        expect(array_keys($snapshot()['sites']))->toBe(['en', 'da']);
    });
});
