<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Telemetry\Logs;
use Cbox\StatamicTelemetry\Telemetry\Traces;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Statamic\Facades\User;

function exploreBackend(): void
{
    config()->set('statamic-telemetry.cp.audit.url', 'http://backend.test');

    Http::fake([
        'http://backend.test/api/search*' => Http::response(['traces' => [
            ['traceID' => 'tr1', 'rootServiceName' => 'cbox-web', 'rootTraceName' => 'GET /', 'startTimeUnixNano' => (string) (time() * 1_000_000_000), 'durationMs' => 42.0,
                // telemetryd returns the matched root span with its attributes.
                'spanSets' => [['spans' => [['spanID' => 'a', 'name' => 'GET /', 'attributes' => [
                    ['key' => 'url.path', 'value' => ['stringValue' => '/']],
                    ['key' => 'http.request.method', 'value' => ['stringValue' => 'GET']],
                    ['key' => 'http.route', 'value' => ['stringValue' => 'entry:pages.page']],
                    ['key' => 'http.response.status_code', 'value' => ['intValue' => '200']],
                    ['key' => 'statamic.type', 'value' => ['stringValue' => 'entry']],
                    ['key' => 'statamic.collection', 'value' => ['stringValue' => 'pages']],
                    ['key' => 'statamic.static_cache', 'value' => ['stringValue' => 'hit']],
                    ['key' => 'statamic.content.title', 'value' => ['stringValue' => 'Home']],
                    ['key' => 'http.request.header.accept', 'value' => ['stringValue' => 'text/html']],
                ]]]]],
            ],
        ]]),
        'http://backend.test/api/traces/*' => Http::response(['batches' => [[
            'scopeSpans' => [['spans' => [
                ['spanId' => 'a', 'parentSpanId' => '', 'name' => 'GET /', 'startTimeUnixNano' => '1700000000000000000', 'endTimeUnixNano' => '1700000000200000000'],
            ]]],
        ]]]),
        'http://backend.test/loki/api/v1/query_range*' => Http::response(['status' => 'success', 'data' => [
            'resultType' => 'streams',
            'result' => [[
                'stream' => ['service_name' => 'cbox-web', 'level' => 'error'],
                'values' => [[(string) (time() * 1_000_000_000), 'boom something failed', ['exception' => 'RuntimeException']]],
            ]],
        ]]),
    ]);
}

it('reads log lines from the Loki API, newest first', function () {
    config()->set('statamic-telemetry.cp.audit.url', 'http://backend.test');
    Http::fake(['http://backend.test/loki/api/v1/query_range*' => Http::response(['status' => 'success', 'data' => [
        'resultType' => 'streams',
        'result' => [[
            'stream' => ['service_name' => 'cbox-web', 'level' => 'warning'],
            'values' => [['1700000000000000000', 'a warning', ['trace_id' => 'abc']]],
        ]],
    ]])]);

    $rows = app(Logs::class)->query(3600);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['level'])->toBe('warning')
        ->and($rows[0]['line'])->toBe('a warning')
        ->and($rows[0]['traceId'])->toBe('abc');
});

it('lists requests from the traces backend', function () {
    exploreBackend();
    $this->actingAs(tap(User::make()->email('a@b.test')->makeSuper())->save());

    $this->get(cp_route('telemetry.explore'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('statamic-telemetry::Explore')
            ->where('signal', 'requests')
            ->where('items.0.name', 'GET /')
            ->where('items.0.id', 'tr1')
            // The row says which page was served, from where — not the route template.
            ->where('items.0.path', '/')
            ->where('items.0.collection', 'pages')
            ->where('items.0.cache', 'hit')
            ->where('items.0.title', 'Home')
            ->where('items.0.status', '200')
            ->where('facets.collection.0.value', 'pages')
            ->where('facets.cache.0.count', 1));
});

it('filters rows by Statamic facets through TraceQL and finds pages by title', function () {
    exploreBackend();
    $this->actingAs(tap(User::make()->email('a@b.test')->makeSuper())->save());

    $this->get(cp_route('telemetry.explore').'?collection=pages&cache=hit&q=home')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.collection', 'pages')
            ->where('items.0.id', 'tr1'));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api/search')
        && str_contains(urldecode($request->url()), 'span.statamic.collection="pages" && span.statamic.static_cache="hit"'));
});

it('lists error logs on the errors signal', function () {
    exploreBackend();
    $this->actingAs(tap(User::make()->email('a@b.test')->makeSuper())->save());

    $this->get(cp_route('telemetry.explore').'?signal=errors')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('signal', 'errors')
            ->where('items.0.level', 'error')
            ->where('items.0.line', 'boom something failed'));
});

it('filters the request list by a facet through a TraceQL selector', function () {
    exploreBackend();
    $this->actingAs(tap(User::make()->email('a@b.test')->makeSuper())->save());

    $this->get(cp_route('telemetry.explore').'?signal=requests&status=500&route='.urlencode('/cp'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', '500')
            ->where('filters.route', '/cp'));

    // The Tempo search carried a TraceQL selector built from both facets.
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/api/search')) {
            return false;
        }
        $url = urldecode($request->url());

        return str_contains($url, 'span.http.response.status_code=500')
            && str_contains($url, 'span.http.route="/cp"');
    });
});

it('filters by a status class as a TraceQL numeric range', function () {
    exploreBackend();
    $this->actingAs(tap(User::make()->email('a@b.test')->makeSuper())->save());

    $this->get(cp_route('telemetry.explore').'?signal=requests&status=4xx')->assertOk();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/api/search')) {
            return false;
        }
        $url = urldecode($request->url());

        return str_contains($url, 'span.http.response.status_code>=400')
            && str_contains($url, 'span.http.response.status_code<500');
    });
});

it('parses span attributes, service and kind for the waterfall detail', function () {
    config()->set('statamic-telemetry.cp.audit.url', 'http://b.test');

    $span = [
        'spanId' => 'a',
        'parentSpanId' => '',
        'name' => 'GET not_found',
        'kind' => 2,
        'startTimeUnixNano' => '1700000000000000000',
        'endTimeUnixNano' => '1700000000041000000',
        'attributes' => [
            ['key' => 'http.route', 'value' => ['stringValue' => 'not_found']],
            ['key' => 'http.response.status_code', 'value' => ['intValue' => '404']],
        ],
    ];
    $batch = [
        'resource' => ['attributes' => [['key' => 'service.name', 'value' => ['stringValue' => 'cbox-web']]]],
        'scopeSpans' => [['spans' => [$span]]],
    ];
    Http::fake(['http://b.test/api/traces/*' => Http::response(['batches' => [$batch]])]);

    $spans = app(Traces::class)->trace('t1');

    expect($spans)->toHaveCount(1)
        ->and($spans[0]->service)->toBe('cbox-web')
        ->and($spans[0]->kind)->toBe('server')
        ->and($spans[0]->attributes['http.route'])->toBe('not_found')
        ->and($spans[0]->attributes['http.response.status_code'])->toBe('404');
});

it('denies explore to a user without the permission', function () {
    $this->actingAs(tap(User::make()->email('nobody@b.test'))->save());

    $this->get(cp_route('telemetry.explore'))->assertRedirect();
});
