<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Telemetry\Logs;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Statamic\Facades\User;

function exploreBackend(): void
{
    config()->set('statamic-telemetry.cp.audit.url', 'http://backend.test');

    Http::fake([
        'http://backend.test/api/search*' => Http::response(['traces' => [
            ['traceID' => 'tr1', 'rootServiceName' => 'cbox-web', 'rootTraceName' => 'GET /', 'startTimeUnixNano' => (string) (time() * 1_000_000_000), 'durationMs' => 42.0],
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
            ->where('items.0.id', 'tr1'));
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

it('loads a trace waterfall as the detail for a selected request', function () {
    exploreBackend();
    $this->actingAs(tap(User::make()->email('a@b.test')->makeSuper())->save());

    $this->get(cp_route('telemetry.explore').'?signal=requests&id=tr1')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedId', 'tr1')
            ->where('detail.spans.0.name', 'GET /'));
});

it('denies explore to a user without the permission', function () {
    $this->actingAs(tap(User::make()->email('nobody@b.test'))->save());

    $this->get(cp_route('telemetry.explore'))->assertRedirect();
});
