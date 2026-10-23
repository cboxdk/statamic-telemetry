<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Audit\AuditLog;
use Cbox\StatamicTelemetry\Audit\AuditQuery;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Statamic\Facades\User;

/**
 * A Loki `query_range` streams response carrying two content-change records.
 * OTLP log-record attributes arrive as per-line structured metadata (the
 * third tuple element), with dots sanitised to underscores.
 *
 * @param  list<array{int, array<string, string>}>  $records  [timestampNano, attributes]
 */
function lokiResponse(array $records): array
{
    return [
        'status' => 'success',
        'data' => [
            'resultType' => 'streams',
            'result' => [[
                'stream' => ['service_name' => 'laravel'],
                'values' => array_map(
                    static fn (array $record): array => [(string) $record[0], 'statamic.content.change', $record[1]],
                    $records,
                ),
            ]],
        ],
    ];
}

function fakeAudit(array $records): void
{
    config()->set('statamic-telemetry.cp.audit.url', 'http://loki.test');

    Http::fake(['http://loki.test/*' => Http::response(lokiResponse($records))]);
}

function twoChanges(): array
{
    return [
        [1_700_000_000_000_000_000, [
            'statamic_content_type' => 'entry',
            'statamic_content_action' => 'published',
            'statamic_content_title' => 'Hello World',
            'statamic_collection' => 'blog',
            'statamic_site' => 'default',
            'user_name' => 'Jane',
            'statamic_content_edit_url' => 'http://localhost/cp/collections/blog/entries/abc',
            'statamic_content_origin' => 'cp',
        ]],
        [1_700_000_001_000_000_000, [
            'statamic_content_type' => 'asset',
            'statamic_content_action' => 'deleted',
            'statamic_content_title' => 'photo.jpg',
            'statamic_asset_container' => 'main',
            'user_name' => 'Bob',
            'statamic_content_origin' => 'cp',
        ]],
    ];
}

it('is disabled until a logs url resolves', function () {
    config()->set('statamic-telemetry.cp.audit.url', null);
    expect(app(AuditLog::class)->enabled())->toBeFalse();

    config()->set('statamic-telemetry.cp.audit.url', 'http://loki.test');
    expect(app(AuditLog::class)->enabled())->toBeTrue();
});

it('reads the newest records back, newest first, with facets', function () {
    fakeAudit(twoChanges());

    $result = app(AuditLog::class)->query(new AuditQuery);

    expect($result->error)->toBeNull()
        ->and($result->rows)->toHaveCount(2)
        ->and($result->rows[0]->action)->toBe('deleted')   // 1_700_000_001 is newer
        ->and($result->rows[0]->title)->toBe('photo.jpg')
        ->and($result->rows[1]->action)->toBe('published')
        ->and($result->rows[1]->editUrl)->toBe('http://localhost/cp/collections/blog/entries/abc')
        ->and($result->types)->toBe(['asset', 'entry'])
        ->and($result->actions)->toBe(['deleted', 'published']);
});

it('filters by facet and free-text search', function () {
    fakeAudit(twoChanges());

    expect(app(AuditLog::class)->query(new AuditQuery(type: 'entry'))->rows)->toHaveCount(1);
    expect(app(AuditLog::class)->query(new AuditQuery(action: 'deleted'))->rows)->toHaveCount(1);

    $found = app(AuditLog::class)->query(new AuditQuery(search: 'photo'));
    expect($found->rows)->toHaveCount(1)
        ->and($found->rows[0]->title)->toBe('photo.jpg');
});

it('reports the window as capped when the backend fills the limit', function () {
    config()->set('statamic-telemetry.cp.audit.limit', 2);
    fakeAudit(twoChanges());

    expect(app(AuditLog::class)->query(new AuditQuery)->capped)->toBeTrue();
});

it('swallows a backend failure into an error result, never throwing', function () {
    config()->set('statamic-telemetry.cp.audit.url', 'http://loki.test');
    Http::fake(['http://loki.test/*' => Http::response('nope', 500)]);

    $result = app(AuditLog::class)->query(new AuditQuery);

    expect($result->error)->not->toBeNull()
        ->and($result->rows)->toBe([]);
});

it('shows the page to a permitted user and lists the changes', function () {
    fakeAudit(twoChanges());
    $this->actingAs(tap(User::make()->email('admin@example.com')->makeSuper())->save());

    $this->get(cp_route('telemetry.audit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('statamic-telemetry::ContentAudit')
            ->where('result.total', 2)
            ->where('result.rows.0.title', 'photo.jpg'));
});

it('denies a user without the permission', function () {
    fakeAudit(twoChanges());
    $this->actingAs(tap(User::make()->email('nobody@example.com'))->save());

    // Statamic redirects an unauthorized CP user rather than returning a bare
    // 403 — either way they never reach the page.
    $this->get(cp_route('telemetry.audit'))
        ->assertRedirect()
        ->assertDontSee('Hello World');
});

it('is not found when no logs backend is configured', function () {
    config()->set('statamic-telemetry.cp.audit.url', null);
    $this->actingAs(tap(User::make()->email('admin@example.com')->makeSuper())->save());

    $this->get(cp_route('telemetry.audit'))->assertNotFound();
});
