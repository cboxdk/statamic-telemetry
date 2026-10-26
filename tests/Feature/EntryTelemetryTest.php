<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Audit\AuditLog;
use Cbox\StatamicTelemetry\Telemetry\EntryTelemetry;
use Cbox\StatamicTelemetry\Telemetry\TimeRange;
use Cbox\StatamicTelemetry\Telemetry\Traces;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/** Make request() look like a Control Panel request — the tab is only added there. */
function inCpRequest(): void
{
    app()->instance('request', Request::create('/cp/collections/pages/entries/about'));
}

function entryBackend(): void
{
    config()->set('statamic-telemetry.cp.audit.url', 'http://b.test');

    Http::fake([
        // Tempo search for this entry's traces.
        'http://b.test/api/search*' => Http::response(['traces' => [
            ['traceID' => 't1', 'rootServiceName' => 'cbox-web', 'rootTraceName' => 'GET entry:blog.blog', 'startTimeUnixNano' => (string) (time() * 1_000_000_000), 'durationMs' => 120.0],
            ['traceID' => 't2', 'rootServiceName' => 'cbox-web', 'rootTraceName' => 'GET entry:blog.blog', 'startTimeUnixNano' => (string) (time() * 1_000_000_000), 'durationMs' => 60.0],
        ]]),
        // Loki audit lines — one for this entry, one for another.
        'http://b.test/loki/api/v1/query_range*' => Http::response(['status' => 'success', 'data' => [
            'resultType' => 'streams',
            'result' => [[
                'stream' => ['service_name' => 'cbox-web'],
                'values' => [
                    [(string) (time() * 1_000_000_000), 'statamic.content.change', ['statamic_content_id' => 'e123', 'statamic_content_action' => 'published', 'user_name' => 'Jane']],
                    [(string) (time() * 1_000_000_000), 'statamic.content.change', ['statamic_content_id' => 'other', 'statamic_content_action' => 'updated']],
                ],
            ]],
        ]]),
    ]);
}

it('queries traces for one entry via TraceQL on statamic.entry.id', function () {
    entryBackend();

    $traces = app(Traces::class)->byEntry('e123', 86400);

    expect($traces)->toHaveCount(2)
        ->and($traces[0]->name)->toBe('GET entry:blog.blog');

    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'statamic.entry.id="e123"'));
});

it('filters the audit log to one entry by content id', function () {
    entryBackend();

    $rows = app(AuditLog::class)->forEntry('e123', 86400);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe('e123')
        ->and($rows[0]->action)->toBe('published');
});

it('tells the entry\'s story: requests, status mix, cache, exceptions on its path and its change history', function () {
    entryBackend();
    $this->actingAs(tap(User::make()->email('admin@example.com')->makeSuper())->save());

    $t = app(EntryTelemetry::class)->story('e123', '/about', TimeRange::window('24h'));

    expect($t['requests']['count'])->toBe(2)
        ->and($t['requests']['p95'])->toBeGreaterThan(0.0)
        ->and($t['requests']['recent'])->toHaveCount(2)
        ->and($t['requests']['series']['t'])->toHaveCount(24)
        ->and(array_sum($t['requests']['series']['ok']))->toBe(2)
        ->and($t['cache'])->toHaveKeys(['hit', 'miss', 'write', 'ratio'])
        ->and($t['changes']['count'])->toBe(1)
        ->and($t['changes']['rows'][0]->action)->toBe('published')
        ->and($t['changes']['rows'][0]->who)->toBe('Jane');

    // The request span is matched explicitly (its attributes carry status and cache outcome),
    // and the exceptions come from error records on the entry's own path.
    Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'kind=server && span.statamic.entry.id="e123"'));
    Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'level="error"} | url_path="/about"'));
});

it('returns empty, never throws, when the backend is down', function () {
    config()->set('statamic-telemetry.cp.audit.url', 'http://b.test');
    Http::fake(['http://b.test/*' => Http::response('down', 500)]);

    $t = app(EntryTelemetry::class)->story('e123', '/about', TimeRange::window('7d'));

    expect($t['requests']['count'])->toBe(0)
        ->and($t['changes']['rows'])->toBe([])
        ->and($t['exceptions']['count'])->toBe(0);
});

it('serves the story as JSON for a real entry, a week by default', function () {
    entryBackend();
    Collection::make('pages')->routes('{slug}')->save();
    $entry = tap(Entry::make()->collection('pages')->slug('about')->data(['title' => 'About']))->save();
    $this->actingAs(tap(User::make()->email('admin@example.com')->makeSuper())->save());

    $this->getJson(cp_route('telemetry.entry', ['id' => $entry->id()]))
        ->assertOk()
        ->assertJsonPath('entry.uri', '/about')
        ->assertJsonPath('entry.title', 'About')
        ->assertJsonPath('window', '7d')
        ->assertJsonPath('requests.count', 2);

    $this->getJson(cp_route('telemetry.entry', ['id' => 'nope']))->assertNotFound();
});

it('adds a full-width Telemetry tab to entry blueprints for users who may view telemetry', function () {
    // The blueprint is resolved (and Blink-cached) with the current user's
    // permissions, as in a CP request: sign in first, then load the entry.
    $this->actingAs(tap(User::make()->email('admin@example.com')->makeSuper())->save());
    inCpRequest();
    Collection::make('pages')->save();
    $entry = tap(Entry::make()->collection('pages')->slug('about'))->save();

    $field = $entry->blueprint()->field('telemetry');

    expect($field)->not->toBeNull()
        ->and($field->type())->toBe('telemetry')
        ->and($field->config()['width'])->toBe(100)
        ->and($field->config()['visibility'])->toBe('computed')
        ->and($entry->blueprint()->tabs()->keys()->all())->toContain('telemetry');
});

it('does not add the tab outside the Control Panel, for a user without telemetry access, or when disabled', function () {
    Collection::make('pages')->save();

    // A signed-in editor on the frontend / API sees the blueprint as everyone else does.
    $this->actingAs(tap(User::make()->email('editor@example.com')->makeSuper())->save());
    $frontend = tap(Entry::make()->collection('pages')->slug('frontend'))->save();
    expect($frontend->blueprint()->field('telemetry'))->toBeNull();

    inCpRequest();

    $this->actingAs(tap(User::make()->email('nobody@example.com'))->save());
    $entry = tap(Entry::make()->collection('pages')->slug('about'))->save();
    expect($entry->blueprint()->field('telemetry'))->toBeNull();

    config()->set('statamic-telemetry.cp.entry_tab', false);
    $this->actingAs(tap(User::make()->email('admin@example.com')->makeSuper())->save());
    $other = tap(Entry::make()->collection('pages')->slug('other'))->save();
    expect($other->blueprint()->field('telemetry'))->toBeNull();
});

it('keeps the audit trail behind its own permission and the endpoint behind the entry\'s view policy', function () {
    entryBackend();
    config()->set('statamic.editions.pro', true); // two non-super users
    Collection::make('pages')->routes('{slug}')->save();
    $entry = tap(Entry::make()->collection('pages')->slug('about')->data(['title' => 'About']))->save();

    // Telemetry, but not the audit log: the story has no changes, and says why.
    $viewer = tap(User::make()->email('viewer@example.com'))->save();
    $viewer->assignRole(tap(Role::make('viewer')->permissions(['access cp', 'view telemetry', 'view pages entries']))->save());
    $this->actingAs($viewer);

    $t = app(EntryTelemetry::class)->story('e123', '/about', TimeRange::window('24h'));
    expect($t['changes'])->toMatchArray(['available' => false, 'count' => 0, 'rows' => []]);

    // Telemetry, but no access to this collection: the endpoint refuses.
    $outsider = tap(User::make()->email('outsider@example.com'))->save();
    $outsider->assignRole(tap(Role::make('outsider')->permissions(['access cp', 'view telemetry']))->save());
    $this->actingAs($outsider);

    $this->getJson(cp_route('telemetry.entry', ['id' => $entry->id()]))->assertForbidden();
});
