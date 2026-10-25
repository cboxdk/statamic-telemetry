<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Statamic\Facades\User;

/** A Prometheus instant vector: [{labels, value}, …]. */
function pageVector(array $series): array
{
    return ['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => array_map(
        static fn (array $s): array => ['metric' => $s['labels'], 'value' => [time(), (string) $s['value']]],
        $series,
    )]];
}

/** A Prometheus range matrix: [{labels, points: [[ts, v], …]}, …]. */
function pageMatrix(array $series): array
{
    return ['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => array_map(
        static fn (array $s): array => ['metric' => $s['labels'], 'values' => array_map(static fn (array $p): array => [$p[0], (string) $p[1]], $s['points'])],
        $series,
    )]];
}

function otlpAttrs(array $attrs): array
{
    return array_map(static fn (string $k, string $v): array => ['key' => $k, 'value' => ['stringValue' => $v]], array_keys($attrs), array_values($attrs));
}

/**
 * One backend for the Statamic pages: Prometheus answers by metric label,
 * the search API answers by what the TraceQL asked for.
 */
function fakeStatamicBackend(): void
{
    config()->set('statamic-telemetry.cp.audit.url', 'http://backend.test');
    $t = time();
    $nano = (string) ($t * 1_000_000_000);

    Http::fake(function ($request) use ($t, $nano) {
        $url = urldecode($request->url());

        if (str_contains($url, '/loki/api/v1/query_range')) {
            return Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => [[
                'stream' => ['service_name' => 'cbox-web', 'level' => 'error'],
                'values' => [
                    [$nano, 'exception', ['exception_type' => 'App\\Exceptions\\PaymentFailed', 'exception_message' => 'Card declined', 'exception_file' => '/app/Billing/Charge.php', 'exception_line' => '42', 'url_path' => '/checkout']],
                    [$nano, 'exception', ['exception_type' => 'RuntimeException', 'exception_message' => 'boom']],
                ],
            ]]]]);
        }

        if (str_contains($url, '/api/v1/query_range') && ! str_contains($url, '/loki/')) {
            return Http::response(pageMatrix([
                ['labels' => ['preset' => 'small'], 'points' => [[$t - 120, 2], [$t - 60, 3], [$t, 0]]],
            ]));
        }

        if (str_contains($url, '/api/v1/query')) {
            return Http::response(pageVector(match (true) {
                str_contains($url, 'by (preset)') => [['labels' => ['preset' => 'small'], 'value' => 5], ['labels' => ['preset' => 'large'], 'value' => 3]],
                str_contains($url, 'by (operation)') => [['labels' => ['operation' => 'hit'], 'value' => 30], ['labels' => ['operation' => 'miss'], 'value' => 10], ['labels' => ['operation' => 'write'], 'value' => 10]],
                default => [],
            }));
        }

        if (str_contains($url, '/api/search')) {
            if (str_contains($url, 'consumer')) {
                return Http::response(['traces' => [[
                    'traceID' => 'j1', 'rootServiceName' => 'cbox-web', 'rootTraceName' => 'App\\Jobs\\SendWelcome process', 'startTimeUnixNano' => $nano, 'durationMs' => 250.0,
                    'spanSets' => [['spans' => [
                        ['spanID' => 'js1', 'name' => 'App\\Jobs\\SendWelcome process', 'startTimeUnixNano' => $nano, 'durationNanos' => '250000000', 'attributes' => otlpAttrs(['laravel.job.class' => 'App\\Jobs\\SendWelcome', 'messaging.destination.name' => 'emails', 'laravel.job.attempts' => '1'])],
                        ['spanID' => 'js2', 'name' => 'App\\Jobs\\Reindex process', 'startTimeUnixNano' => $nano, 'durationNanos' => '90000000', 'status' => ['code' => 2, 'message' => 'timeout'], 'attributes' => otlpAttrs(['laravel.job.class' => 'App\\Jobs\\Reindex', 'messaging.destination.name' => 'default', 'laravel.job.attempts' => '3'])],
                    ]]],
                ]]]);
            }

            if (str_contains($url, 'glide')) {
                return Http::response(['traces' => [[
                    'traceID' => 'g1', 'rootServiceName' => 'cbox-web', 'rootTraceName' => 'GET entry:pages.page', 'startTimeUnixNano' => $nano, 'durationMs' => 80.0,
                    'spanSets' => [['spans' => [
                        ['spanID' => 's1', 'name' => 'statamic.glide.generate', 'startTimeUnixNano' => $nano, 'durationNanos' => '12000000', 'attributes' => otlpAttrs(['statamic.glide.preset' => 'small', 'statamic.glide.path' => 'img/hero.jpg', 'statamic.asset.container' => 'assets'])],
                        ['spanID' => 's2', 'name' => 'statamic.glide.generate', 'startTimeUnixNano' => $nano, 'durationNanos' => '9000000', 'attributes' => otlpAttrs(['statamic.glide.preset' => 'large', 'statamic.glide.path' => 'img/hero.jpg'])],
                    ]]],
                ]]]);
            }

            return Http::response(['traces' => [
                ['traceID' => 'c1', 'rootServiceName' => 'cbox-web', 'rootTraceName' => 'GET entry:pages.page', 'startTimeUnixNano' => $nano, 'durationMs' => 5.0,
                    'spanSets' => [['spans' => [['spanID' => 'r1', 'name' => 'GET entry:pages.page', 'attributes' => otlpAttrs(['url.path' => '/about', 'http.request.method' => 'GET', 'http.response.status_code' => '200', 'statamic.collection' => 'pages', 'statamic.static_cache' => 'hit', 'statamic.content.title' => 'About'])]]]]],
                ['traceID' => 'c2', 'rootServiceName' => 'cbox-web', 'rootTraceName' => 'GET entry:blog.article', 'startTimeUnixNano' => $nano, 'durationMs' => 120.0,
                    'spanSets' => [['spans' => [['spanID' => 'r2', 'name' => 'GET entry:blog.article', 'attributes' => otlpAttrs(['url.path' => '/blog/hello', 'http.request.method' => 'GET', 'http.response.status_code' => '200', 'statamic.collection' => 'blog', 'statamic.static_cache' => 'miss'])]]]]],
            ]]);
        }

        return Http::response([], 404);
    });
}

beforeEach(function () {
    fakeStatamicBackend();
    $this->actingAs(tap(User::make()->email('admin@example.com')->makeSuper())->save());
});

it('renders the Glide page: generations by preset on top, every generation as a row underneath', function () {
    $this->get(cp_route('telemetry.page', ['page' => 'glide']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('statamic-telemetry::AreaPage')
            ->where('page.title', 'Glide')
            ->where('aggregate.stats.0.label', 'Generations')
            ->where('aggregate.stats.0.value', '8')
            ->where('aggregate.breakdown.rows.0.preset', 'small')
            ->where('aggregate.chart.series.0.name', 'small')
            ->where('rows.kind', 'spans')
            ->where('rows.items.0.preset', 'small')
            ->where('rows.items.0.label', 'img/hero.jpg')
            ->where('rows.items.0.request', 'GET entry:pages.page')
            ->where('rows.items.0.traceId', 'g1')
            ->where('rows.facets.preset.0.value', 'small')
            ->where('rows.total', 2));
});

it('renders the Static cache page from the server spans, grouped by collection', function () {
    $this->get(cp_route('telemetry.page', ['page' => 'static-cache']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('aggregate.stats.0.label', 'Hit ratio')
            ->where('aggregate.stats.0.value', '75%')
            ->where('rows.kind', 'requests')
            ->where('rows.items.0.path', '/about')
            ->where('rows.items.0.cache', 'hit')
            ->where('rows.groups.rows.0.collection', 'pages')
            ->where('rows.groups.rows.0.hit', 1)
            ->where('rows.facets.cache.0.value', 'hit'));

    // The rows query asked for server spans with a static-cache outcome.
    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'kind=server && span.statamic.static_cache=~".+"'));
});

it('filters rows by a facet and free text without touching the aggregate', function () {
    $this->get(cp_route('telemetry.page', ['page' => 'static-cache']).'?cache=miss')
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.cache', 'miss')
            ->where('rows.items.0.path', '/blog/hello')
            ->count('rows.items', 1)
            ->where('rows.facets.cache.1.value', 'miss')); // facets are counted before the filter

    $this->get(cp_route('telemetry.page', ['page' => 'glide']).'?q=hero')
        ->assertInertia(fn (Assert $page) => $page->count('rows.items', 2));

    $this->get(cp_route('telemetry.page', ['page' => 'glide']).'?q=nothing-like-this')
        ->assertInertia(fn (Assert $page) => $page->count('rows.items', 0)->where('rows.total', 2));
});

it('is not found for a page that does not exist', function () {
    $this->get('/cp/telemetry/widgets')->assertNotFound();
});

it('marks the page active in the in-page nav', function () {
    $this->get(cp_route('telemetry.page', ['page' => 'forms']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('nav', fn ($nav) => collect($nav)->firstWhere('active', true)['key'] === 'forms'));
});

it('renders the Jobs page: every run as a row, failures flagged, facets by job and queue', function () {
    $this->get(cp_route('telemetry.page', ['page' => 'jobs']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.group', 'activity')
            ->where('aggregate.stats.0.label', 'Processed')
            ->where('rows.kind', 'spans')
            ->where('rows.items.0.job', 'SendWelcome')
            ->where('rows.items.0.queue', 'emails')
            ->where('rows.items.0.outcome', 'ok')
            ->where('rows.items.1.outcome', 'failed')
            ->where('rows.items.1.error', 'error: timeout')
            ->where('rows.facets.queue.0.value', 'emails'));

    $this->get(cp_route('telemetry.page', ['page' => 'jobs']).'?outcome=failed')
        ->assertInertia(fn (Assert $page) => $page->count('rows.items', 1)->where('rows.items.0.job', 'Reindex'));
});

it('renders the Exceptions page from error-level log records, typed and searchable', function () {
    $this->get(cp_route('telemetry.page', ['page' => 'exceptions']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('aggregate.stats.0.value', '2')
            ->where('aggregate.breakdown.rows.0.count', 1)
            ->where('rows.kind', 'logs')
            ->where('rows.items.0.type', 'PaymentFailed')
            ->where('rows.items.0.message', 'Card declined')
            ->where('rows.items.0.origin', 'Charge.php:42')
            ->where('rows.items.0.path', '/checkout')
            ->where('rows.facets.type.0.count', 1));

    $this->get(cp_route('telemetry.page', ['page' => 'exceptions']).'?q=declined')
        ->assertInertia(fn (Assert $page) => $page->count('rows.items', 1));
});

it('renders the Requests page with the route table on top and every request underneath', function () {
    $this->get(cp_route('telemetry.page', ['page' => 'requests']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('aggregate.stats.0.label', 'Requests')
            ->where('aggregate.chart.series.0.name', '1/2/3XX')
            ->where('rows.kind', 'requests')
            ->where('rows.items.0.path', '/about')
            ->where('rows.facets.collection.0.value', 'pages'));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api/search') && str_contains(urldecode($request->url()), '{kind=server}'));
});
