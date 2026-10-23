<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Audit\AuditLog;
use Cbox\StatamicTelemetry\Telemetry\EntryTelemetry;
use Cbox\StatamicTelemetry\Telemetry\Traces;
use Illuminate\Support\Facades\Http;

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

it('gathers per-entry requests and change history', function () {
    entryBackend();

    $t = app(EntryTelemetry::class)->for('e123', 86400);

    expect($t['requests']['count'])->toBe(2)
        ->and($t['requests']['p95'])->toBeGreaterThan(0.0)
        ->and($t['requests']['recent'])->toHaveCount(2)
        ->and($t['changes'])->toHaveCount(1)
        ->and($t['changes'][0]['action'])->toBe('published')
        ->and($t['changes'][0]['who'])->toBe('Jane');
});

it('returns empty, never throws, when the backend is down', function () {
    config()->set('statamic-telemetry.cp.audit.url', 'http://b.test');
    Http::fake(['http://b.test/*' => Http::response('down', 500)]);

    $t = app(EntryTelemetry::class)->for('e123');

    expect($t['requests']['count'])->toBe(0)
        ->and($t['changes'])->toBe([]);
});
