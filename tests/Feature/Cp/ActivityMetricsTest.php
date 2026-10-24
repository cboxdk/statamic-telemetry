<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Telemetry\ActivityMetrics;
use Illuminate\Support\Facades\Http;

function promVec(array $series): array
{
    return ['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => array_map(
        static fn (array $s): array => ['metric' => $s['labels'], 'value' => [time(), (string) $s['value']]],
        $series,
    )]];
}

function fakeActivity(): void
{
    config()->set('statamic-telemetry.cp.audit.url', 'http://prom.test');

    Http::fake(function ($request) {
        $url = urldecode($request->url());

        if (str_contains($url, '/api/v1/query_range')) {
            return Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => [
                ['metric' => [], 'values' => [[time() - 60, '3'], [time(), '4']]],
            ]]]);
        }

        return match (true) {
            str_contains($url, 'queue_metrics_workers_utilization_percent') => Http::response(promVec([['labels' => ['window' => 'current'], 'value' => 42]])),
            // Per-job p95 (before the overall quantile).
            str_contains($url, 'sum by (job_name, le)') => Http::response(promVec([
                ['labels' => ['job_name' => 'Statamic\\Search\\InsertMultipleJob'], 'value' => 0.05],
            ])),
            str_contains($url, 'sum by (job_name, queue)') => Http::response(promVec([
                ['labels' => ['job_name' => 'Statamic\\Search\\InsertMultipleJob', 'queue' => 'sync'], 'value' => 14],
                ['labels' => ['job_name' => 'Statamic\\Search\\UpdateItemIndexes', 'queue' => 'sync'], 'value' => 11],
            ])),
            str_contains($url, 'queue_job_duration_seconds_bucket') => Http::response(promVec([['labels' => [], 'value' => 0.08]])),
            str_contains($url, 'sum by (connection)') => Http::response(promVec([
                ['labels' => ['connection' => 'fake'], 'value' => 985],
                ['labels' => ['connection' => 'sqlite'], 'value' => 256],
            ])),
            str_contains($url, 'sum by (disk, operation)') => Http::response(promVec([
                ['labels' => ['disk' => 'assets', 'operation' => 'get'], 'value' => 29],
            ])),
            str_contains($url, 'sum by (server_address, le)') => Http::response(promVec([['labels' => ['server_address' => 'api.test'], 'value' => 0.2]])),
            str_contains($url, 'error_type!=""') && str_contains($url, 'sum by (server_address)') => Http::response(promVec([['labels' => ['server_address' => 'api.test'], 'value' => 6]])),
            str_contains($url, 'sum by (server_address)') => Http::response(promVec([['labels' => ['server_address' => 'api.test'], 'value' => 120]])),
            // Scalars.
            str_contains($url, 'queue_jobs_processed_total') => Http::response(promVec([['labels' => [], 'value' => 28]])),
            str_contains($url, 'queue_jobs_dispatched_total') => Http::response(promVec([['labels' => [], 'value' => 30]])),
            str_contains($url, 'db_queries_n_plus_one_total') => Http::response(promVec([['labels' => [], 'value' => 2]])),
            str_contains($url, 'db_queries_duplicated_total') => Http::response(promVec([['labels' => [], 'value' => 5]])),
            str_contains($url, 'db_queries_total') => Http::response(promVec([['labels' => [], 'value' => 1241]])),
            str_contains($url, 'error_type!=""') => Http::response(promVec([['labels' => [], 'value' => 62]])),
            str_contains($url, 'http_client_request_duration_seconds_count') => Http::response(promVec([['labels' => [], 'value' => 1232]])),
            str_contains($url, 'http_client_request_duration_seconds_bucket') => Http::response(promVec([['labels' => [], 'value' => 0.2]])),
            str_contains($url, 'storage_operations_total') => Http::response(promVec([['labels' => [], 'value' => 29]])),
            default => Http::response(promVec([])),
        };
    });
}

it('builds the Activity section from jobs, queries, storage and http metrics', function () {
    fakeActivity();

    $a = app(ActivityMetrics::class)->overview(3600);

    // Jobs: totals, utilisation, and a per-job table with its short class + p95.
    expect($a['jobs']['processed'])->toBe(28.0)
        ->and($a['jobs']['dispatched'])->toBe(30.0)
        ->and($a['jobs']['utilization'])->toBe(42.0)
        ->and($a['jobs']['durationP95'])->toBe(80.0)
        ->and($a['jobs']['byJob'][0]['job'])->toBe('InsertMultipleJob')
        ->and($a['jobs']['byJob'][0]['p95'])->toBe(50.0)
        // Queries: totals + N+1 / duplicated, busiest connection first.
        ->and($a['queries']['total'])->toBe(1241.0)
        ->and($a['queries']['nPlusOne'])->toBe(2.0)
        ->and($a['queries']['duplicated'])->toBe(5.0)
        ->and($a['queries']['byConnection'][0]['connection'])->toBe('fake')
        // Storage: a single disk/op with full share.
        ->and($a['storage']['operations'][0]['disk'])->toBe('assets')
        ->and($a['storage']['operations'][0]['share'])->toBe(1.0)
        // Outgoing HTTP: total, error rate and p95.
        ->and($a['http']['total'])->toBe(1232.0)
        ->and($a['http']['errorRate'])->toBe(round(62 / 1232, 4))
        ->and($a['http']['p95'])->toBe(200.0)
        ->and($a['http']['byHost'][0]['host'])->toBe('api.test');

    expect(app(ActivityMetrics::class)->hasData($a))->toBeTrue();
});

it('comes back empty, never throwing, when the backend is down', function () {
    config()->set('statamic-telemetry.cp.audit.url', 'http://prom.test');
    Http::fake(['http://prom.test/*' => Http::response('nope', 500)]);

    $a = app(ActivityMetrics::class)->overview(3600);

    expect($a['jobs']['processed'])->toBe(0.0)
        ->and(app(ActivityMetrics::class)->hasData($a))->toBeFalse();
});
