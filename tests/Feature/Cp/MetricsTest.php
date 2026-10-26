<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Telemetry\Metrics;
use Cbox\StatamicTelemetry\Telemetry\TelemetryMetrics;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Statamic\Facades\User;

/** A Prometheus instant vector. */
function vector(array $series): array
{
    return [
        'status' => 'success',
        'data' => [
            'resultType' => 'vector',
            'result' => array_map(static fn (array $s): array => [
                'metric' => $s['labels'],
                'value' => [time(), (string) $s['value']],
            ], $series),
        ],
    ];
}

/** A Prometheus range matrix. Each series: {labels, points: [[ts, value], …]}. */
function matrix(array $series): array
{
    return [
        'status' => 'success',
        'data' => [
            'resultType' => 'matrix',
            'result' => array_map(static fn (array $s): array => [
                'metric' => $s['labels'],
                'values' => array_map(static fn (array $p): array => [$p[0], (string) $p[1]], $s['points']),
            ], $series),
        ],
    ];
}

/**
 * Fake the PromQL queries TelemetryMetrics issues, routed by endpoint (instant
 * vs range) and query shape — so route totals, server errors, percentiles and
 * the over-time series all resolve to distinct, realistic data.
 */
function fakeProm(): void
{
    config()->set('statamic-telemetry.cp.audit.url', 'http://prom.test');

    Http::fake(function ($request) {
        $url = urldecode($request->url());
        $t = time();

        if (str_contains($url, '/api/v1/query_range')) {
            return match (true) {
                str_contains($url, 'sum by (http_response_status_code)') => Http::response(matrix([
                    ['labels' => ['http_response_status_code' => '200'], 'points' => [[$t - 120, 5], [$t - 60, 7], [$t, 6]]],
                    ['labels' => ['http_response_status_code' => '500'], 'points' => [[$t - 120, 0], [$t - 60, 1], [$t, 0]]],
                ])),
                str_contains($url, 'sum by (http_route)') => Http::response(matrix([
                    ['labels' => ['http_route' => 'entry:blog.article'], 'points' => [[$t - 120, 3], [$t - 60, 4], [$t, 2]]],
                ])),
                str_contains($url, 'histogram_quantile') => Http::response(matrix([
                    ['labels' => [], 'points' => [[$t - 120, 0.2], [$t - 60, 0.18], [$t, 0.22]]],
                ])),
                // Duration mean is sum/count fetched separately then divided in PHP.
                str_contains($url, 'duration_seconds_sum') => Http::response(matrix([
                    ['labels' => [], 'points' => [[$t - 120, 0.1], [$t - 60, 0.12], [$t, 0.11]]],
                ])),
                str_contains($url, 'duration_seconds_count') => Http::response(matrix([
                    ['labels' => [], 'points' => [[$t - 120, 1], [$t - 60, 1], [$t, 1]]],
                ])),
                default => Http::response(matrix([])),
            };
        }

        return match (true) {
            // Per-route server errors — before the generic route-totals match.
            str_contains($url, 'http_response_status_code=~"5..') => Http::response(vector([
                ['labels' => ['http_route' => 'entry:blog.article'], 'value' => 2],
            ])),
            // Per-route p95 (histogram grouped by route + le).
            str_contains($url, 'http_route, le') => Http::response(vector([
                ['labels' => ['http_route' => 'entry:blog.article'], 'value' => 0.2],
                ['labels' => ['http_route' => '/cp/telemetry'], 'value' => 0.05],
            ])),
            // Stache warm p95 — before the generic request percentile.
            str_contains($url, 'statamic_stache_warm_duration_seconds_bucket') => Http::response(vector([
                ['labels' => [], 'value' => 0.3],
            ])),
            // Request latency percentiles (histogram over all le).
            str_contains($url, 'histogram_quantile') => Http::response(vector([
                ['labels' => [], 'value' => 0.15],
            ])),
            // Request volume split by status class.
            str_contains($url, 'sum by (http_response_status_code)') => Http::response(vector([
                ['labels' => ['http_response_status_code' => '200'], 'value' => 100],
                ['labels' => ['http_response_status_code' => '302'], 'value' => 10],
                ['labels' => ['http_response_status_code' => '404'], 'value' => 5],
                ['labels' => ['http_response_status_code' => '500'], 'value' => 2],
            ])),
            // Route totals.
            str_contains($url, 'sum by (http_route)') => Http::response(vector([
                ['labels' => ['http_route' => 'entry:blog.article'], 'value' => 42],
                ['labels' => ['http_route' => '/cp/telemetry'], 'value' => 5],
            ])),
            // Mean latency (sum / count) — and its previous-window form.
            str_contains($url, 'duration_seconds_sum') => Http::response(vector([['labels' => [], 'value' => 0.12]])),
            // Previous-window request total (period-over-period delta).
            str_contains($url, 'offset') && str_contains($url, 'duration_seconds_count') => Http::response(vector([['labels' => [], 'value' => 90]])),
            str_contains($url, 'statamic_static_cache_operations_total') => Http::response(vector([
                ['labels' => ['operation' => 'hit'], 'value' => 80],
                ['labels' => ['operation' => 'miss'], 'value' => 20],
            ])),
            str_contains($url, 'statamic_stache_warms_total') => Http::response(vector([['labels' => [], 'value' => 12]])),
            str_contains($url, 'statamic_glide_generations_total') => Http::response(vector([
                ['labels' => ['preset' => 'thumbnail'], 'value' => 9],
            ])),
            str_contains($url, 'statamic_forms_submissions_total') => Http::response(vector([
                ['labels' => ['form' => 'contact'], 'value' => 4],
            ])),
            str_contains($url, 'statamic_content_changes_total') => Http::response(vector([
                ['labels' => ['type' => 'entry', 'action' => 'published'], 'value' => 3],
            ])),
            default => Http::response(vector([])),
        };
    });
}

it('parses an instant vector from the Prometheus API', function () {
    config()->set('statamic-telemetry.cp.audit.url', 'http://prom.test');
    Http::fake(['http://prom.test/*' => Http::response(vector([
        ['labels' => ['http_route' => '/'], 'value' => 7.5],
    ]))]);

    $series = app(Metrics::class)->instant('whatever');

    expect($series)->toHaveCount(1)
        ->and($series[0]['labels']['http_route'])->toBe('/')
        ->and($series[0]['value'])->toBe(7.5);
});

it('builds the metrics overview: status split, latency, routes, hit ratio', function () {
    fakeProm();

    $o = app(TelemetryMetrics::class)->overview(3600);

    // Requests split by status class.
    expect($o->statusClasses)->toBe(['1xx' => 0.0, '2xx' => 100.0, '3xx' => 10.0, '4xx' => 5.0, '5xx' => 2.0])
        ->and($o->requestTotal)->toBe(117.0)
        ->and($o->errorRate)->toBe(round(2 / 117, 4))
        // Latency: avg + histogram percentiles (ms).
        ->and($o->avg)->toBe(120.0)
        ->and($o->p50)->toBe(150.0)
        ->and($o->p95)->toBe(150.0)
        // Routes needing attention — most server errors first, with p95 + trend.
        ->and($o->routes[0]['route'])->toBe('entry:blog.article')
        ->and($o->routes[0]['total'])->toBe(42.0)
        ->and($o->routes[0]['errors'])->toBe(2.0)
        ->and($o->routes[0]['p95'])->toBe(200.0)
        ->and($o->routes[0]['trend'])->toBe([3.0, 4.0, 2.0]);
});

it('builds aligned over-time series for the stacked and latency charts', function () {
    fakeProm();

    $o = app(TelemetryMetrics::class)->overview(3600);

    expect($o->statusSeries['ok'])->toBe([5.0, 7.0, 6.0])
        ->and($o->statusSeries['server'])->toBe([0.0, 1.0, 0.0])
        ->and($o->statusSeries['t'])->toHaveCount(3)                      // shared x axis (unix seconds)
        ->and($o->durationSeries['avg'])->toBe([100.0, 120.0, 110.0])     // seconds → ms
        ->and($o->durationSeries['p95'])->toBe([200.0, 180.0, 220.0])
        ->and($o->durationSeries['t'])->toHaveCount(3);
});

it('computes period-over-period deltas from the previous window', function () {
    fakeProm();

    $o = app(TelemetryMetrics::class)->overview(3600);

    // 117 requests this window vs 90 the previous one → +30%.
    expect($o->delta['requests'])->toBe(round((117 - 90) / 90, 4))
        ->and($o->delta)->toHaveKeys(['requests', 'errorRate', 'avg', 'p95']);
});

it('aggregates requests by a dimension for the Explore group-by', function () {
    fakeProm();

    $rows = app(TelemetryMetrics::class)->groupBy('http_route', 3600);

    expect($rows[0]['value'])->toBe('entry:blog.article')   // busiest first
        ->and($rows[0]['count'])->toBe(42.0)
        ->and($rows[0]['errors'])->toBe(2.0)
        ->and($rows[0]['errorRate'])->toBe(round(2 / 42, 4))
        ->and($rows[0]['p95'])->toBe(200.0);
});

it('builds the Explore summary and facets from metrics', function () {
    fakeProm();

    $metrics = app(TelemetryMetrics::class);
    $summary = $metrics->summary(3600);
    $facets = $metrics->facets(3600);

    expect($summary['requests'])->toBe(117.0)
        ->and($summary['errorRate'])->toBe(round(2 / 117, 4))
        ->and($summary['p95'])->toBe(150.0)
        ->and($facets['status'][0]['value'])->toBe('200')         // busiest first
        ->and($facets['status'][0]['count'])->toBe(100.0)
        ->and($facets['route'][0]['value'])->toBe('entry:blog.article');
});

it('renders the metric section on the dashboard', function () {
    fakeProm();
    $this->actingAs(tap(User::make()->email('admin@example.com')->makeSuper())->save());

    // Overview tab: requests split by status class + routes needing attention.
    $this->get(cp_route('telemetry.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('statamic-telemetry::Telemetry')
            ->where('metrics.statusClasses.2xx', 100)
            ->where('metrics.routes.0.route', 'entry:blog.article'));
});

it('honours a brushed from/to range over the look-back window', function () {
    fakeProm();
    $this->actingAs(tap(User::make()->email('brush@example.com')->makeSuper())->save());

    $to = time() - 1800;
    $from = $to - 1800;

    $this->get(cp_route('telemetry.dashboard')."?from={$from}&to={$to}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('from', $from)->where('to', $to));

    // The instant metric queries were evaluated at the range end, not "now".
    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'time='.$to));
});

it('never throws when the metrics backend is down', function () {
    config()->set('statamic-telemetry.cp.audit.url', 'http://prom.test');
    Http::fake(['http://prom.test/*' => Http::response('nope', 500)]);

    $overview = app(TelemetryMetrics::class)->overview(3600);

    expect($overview->routes)->toBe([])
        ->and($overview->hasData())->toBeFalse();
});
