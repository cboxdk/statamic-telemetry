<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Telemetry\Deployments;
use Cbox\StatamicTelemetry\Telemetry\Exceptions;
use Illuminate\Support\Facades\Http;

it('reads deployment markers from the Loki API, newest first', function () {
    config()->set('statamic-telemetry.cp.audit.url', 'http://b.test');

    $now = time() * 1_000_000_000;
    Http::fake(['http://b.test/loki/api/v1/query_range*' => Http::response(['status' => 'success', 'data' => [
        'resultType' => 'streams',
        'result' => [[
            'stream' => ['service_name' => 'cbox-web'],
            'values' => [
                [(string) $now, 'app.deployment', ['deployment_id' => 'abc123', 'deployment_notes' => 'v1.2.3']],
                [(string) ($now - 3_600_000_000_000), 'app.deployment', ['deployment_id' => 'old99']],
                [(string) $now, 'statamic.content.change', ['statamic_content_id' => 'x']], // not a deploy — ignored
            ],
        ]],
    ]])]);

    $rows = app(Deployments::class)->window(86400);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['id'])->toBe('abc123')
        ->and($rows[0]['notes'])->toBe('v1.2.3')
        ->and($rows[1]['id'])->toBe('old99')
        ->and($rows[1]['notes'])->toBeNull();

    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'app.deployment'));
});

it('returns no deployment markers when the backend is unset or down', function () {
    expect(app(Deployments::class)->window(86400))->toBe([]);

    config()->set('statamic-telemetry.cp.audit.url', 'http://b.test');
    Http::fake(['http://b.test/*' => Http::response('down', 500)]);

    expect(app(Deployments::class)->window(86400))->toBe([]);
});

it('builds the exceptions card: count, trend and recent lines', function () {
    config()->set('statamic-telemetry.cp.audit.url', 'http://b.test');

    $now = time() * 1_000_000_000;
    Http::fake(['http://b.test/loki/api/v1/query_range*' => Http::response(['status' => 'success', 'data' => [
        'resultType' => 'streams',
        'result' => [[
            'stream' => ['service_name' => 'cbox-web', 'level' => 'error'],
            'values' => [
                [(string) $now, 'boom one', ['exception' => 'RuntimeException']],
                [(string) ($now - 1_000_000_000), 'boom two', []],
            ],
        ]],
    ]])]);

    $e = app(Exceptions::class)->overview(3600);

    expect($e['count'])->toBe(2)
        ->and($e['capped'])->toBeFalse()
        ->and($e['recent'][0]['line'])->toBe('boom one')
        ->and(array_sum($e['trend']))->toBe(2);

    // The card queries the error level only.
    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'level="error"'));
});
