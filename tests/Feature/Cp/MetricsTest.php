<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Telemetry\Metrics;
use Cbox\StatamicTelemetry\Telemetry\TelemetryMetrics;
use Illuminate\Support\Facades\Http;
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

/** Fake the three PromQL queries TelemetryMetrics issues, by metric name. */
function fakeProm(): void
{
    config()->set('statamic-telemetry.cp.audit.url', 'http://prom.test');

    Http::fake(function ($request) {
        $url = urldecode($request->url());

        if (str_contains($url, 'http_server_request_duration_seconds_count')) {
            return Http::response(vector([
                ['labels' => ['http_route' => 'entry:blog.article'], 'value' => 42],
                ['labels' => ['http_route' => '/cp/telemetry'], 'value' => 5],
            ]));
        }

        if (str_contains($url, 'statamic_static_cache_operations_total')) {
            return Http::response(vector([
                ['labels' => ['operation' => 'hit'], 'value' => 80],
                ['labels' => ['operation' => 'miss'], 'value' => 20],
            ]));
        }

        if (str_contains($url, 'statamic_content_changes_total')) {
            return Http::response(vector([
                ['labels' => ['type' => 'entry', 'action' => 'published'], 'value' => 3],
            ]));
        }

        return Http::response(vector([]));
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

it('builds the metrics overview: routes, hit ratio, content changes', function () {
    fakeProm();

    $overview = app(TelemetryMetrics::class)->overview(3600);

    expect($overview->routes[0]['route'])->toBe('entry:blog.article')   // busiest first
        ->and($overview->routes[0]['count'])->toBe(42.0)
        ->and($overview->cacheHitRatio)->toBe(0.8)                       // 80 / (80 + 20)
        ->and($overview->cacheOperations)->toBe(['hit' => 80.0, 'miss' => 20.0])
        ->and($overview->contentChanges[0]['type'])->toBe('entry')
        ->and($overview->contentChanges[0]['action'])->toBe('published');
});

it('returns an empty hit ratio when the cache has no hits or misses', function () {
    config()->set('statamic-telemetry.cp.audit.url', 'http://prom.test');
    Http::fake(['http://prom.test/*' => Http::response(vector([]))]);

    expect(app(TelemetryMetrics::class)->overview(3600)->cacheHitRatio)->toBeNull();
});

it('renders the metric section on the dashboard', function () {
    fakeProm();
    $this->actingAs(tap(User::make()->email('admin@example.com')->makeSuper())->save());

    $this->get(cp_route('telemetry.dashboard'))
        ->assertOk()
        ->assertSee('Requests by route')
        ->assertSee('entry:blog.article')
        ->assertSee('Static cache');
});

it('never throws when the metrics backend is down', function () {
    config()->set('statamic-telemetry.cp.audit.url', 'http://prom.test');
    Http::fake(['http://prom.test/*' => Http::response('nope', 500)]);

    $overview = app(TelemetryMetrics::class)->overview(3600);

    expect($overview->routes)->toBe([])
        ->and($overview->hasData())->toBeFalse();
});
