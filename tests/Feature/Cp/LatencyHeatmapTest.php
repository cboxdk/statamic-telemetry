<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Telemetry\LatencyHeatmap;
use Illuminate\Support\Facades\Http;

function heatmapTrace(float $durationMs): array
{
    return [
        'traceID' => uniqid(),
        'rootServiceName' => 'x',
        'rootTraceName' => 'GET /',
        'startTimeUnixNano' => (string) (time() * 1_000_000_000),
        'durationMs' => $durationMs,
    ];
}

it('buckets traces into a latency × time heatmap', function () {
    config()->set('statamic-telemetry.cp.audit.url', 'http://b.test');

    Http::fake(['http://b.test/api/search*' => Http::response(['traces' => [
        heatmapTrace(30.0),   // <50ms band
        heatmapTrace(40.0),   // <50ms band (same column)
        heatmapTrace(3000.0), // >2.5s band
    ]])]);

    $h = app(LatencyHeatmap::class)->build(3600);

    expect($h['bands'])->toHaveCount(7)
        ->and($h['bands'][0])->toBe('<50ms')
        ->and($h['bands'][6])->toBe('>2.5s')
        ->and($h['cols'])->toHaveCount(40)
        ->and($h['max'])->toBe(2);

    // The 3s request lands in the slowest band.
    $slow = array_values(array_filter($h['cells'], static fn (array $c): bool => $c[1] === 6));
    expect($slow[0][2])->toBe(1);
});

it('returns an empty heatmap (but keeps band labels) when there are no traces', function () {
    config()->set('statamic-telemetry.cp.audit.url', 'http://b.test');
    Http::fake(['http://b.test/api/search*' => Http::response(['traces' => []])]);

    $h = app(LatencyHeatmap::class)->build(3600);

    expect($h['cells'])->toBe([])
        ->and($h['cols'])->toBe([])
        ->and($h['bands'])->toHaveCount(7);
});
