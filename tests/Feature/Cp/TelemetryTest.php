<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Telemetry\Requests;
use Cbox\StatamicTelemetry\Telemetry\Traces;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Statamic\Facades\User;

/** A Tempo search response with three traces across two endpoints. */
function tempoSearch(): array
{
    $now = time() * 1_000_000_000;

    return [
        'traces' => [
            ['traceID' => 'aaaa', 'rootServiceName' => 'cbox-web', 'rootTraceName' => 'GET /', 'startTimeUnixNano' => (string) ($now - 1_000_000_000), 'durationMs' => 100.0],
            ['traceID' => 'bbbb', 'rootServiceName' => 'cbox-web', 'rootTraceName' => 'GET /', 'startTimeUnixNano' => (string) ($now - 2_000_000_000), 'durationMs' => 300.0],
            ['traceID' => 'cccc', 'rootServiceName' => 'cbox-web', 'rootTraceName' => 'POST /login', 'startTimeUnixNano' => (string) ($now - 3_000_000_000), 'durationMs' => 50.0],
        ],
    ];
}

function tempoTrace(): array
{
    $t0 = 1_700_000_000_000_000_000;

    return [
        'batches' => [[
            'scopeSpans' => [[
                'spans' => [
                    ['spanId' => 'root', 'parentSpanId' => '', 'name' => 'GET /', 'startTimeUnixNano' => (string) $t0, 'endTimeUnixNano' => (string) ($t0 + 200_000_000)],
                    ['spanId' => 'q', 'parentSpanId' => 'root', 'name' => 'db.query', 'startTimeUnixNano' => (string) ($t0 + 50_000_000), 'endTimeUnixNano' => (string) ($t0 + 70_000_000)],
                ],
            ]],
        ]],
    ];
}

function fakeTempo(): void
{
    config()->set('statamic-telemetry.cp.audit.url', 'http://tempo.test');
    Http::fake([
        'http://tempo.test/api/search*' => Http::response(tempoSearch()),
        'http://tempo.test/api/traces/*' => Http::response(tempoTrace()),
    ]);
}

it('parses traces from the Tempo search API', function () {
    fakeTempo();

    $traces = app(Traces::class)->search(3600);

    expect($traces)->toHaveCount(3)
        ->and($traces[0]->name)->toBe('GET /')      // newest first
        ->and($traces[0]->durationMs)->toBe(100.0)
        ->and($traces[0]->service)->toBe('cbox-web');
});

it('computes the requests overview: count, percentiles, slowest endpoints', function () {
    fakeTempo();

    $overview = app(Requests::class)->overview(3600);

    expect($overview->count)->toBe(3)
        ->and($overview->maxMs)->toBe(300.0)
        ->and($overview->p50)->toBeGreaterThan(0.0)
        ->and($overview->endpoints[0]['name'])->toBe('GET /')      // slowest by avg
        ->and($overview->endpoints[0]['count'])->toBe(2)
        ->and($overview->recent)->toHaveCount(3);
});

it('builds a trace waterfall with nesting depth', function () {
    fakeTempo();

    $spans = app(Traces::class)->trace('aaaa');

    expect($spans)->toHaveCount(2)
        ->and($spans[0]->name)->toBe('GET /')
        ->and($spans[0]->depth)->toBe(0)
        ->and($spans[1]->name)->toBe('db.query')
        ->and($spans[1]->depth)->toBe(1)
        ->and($spans[1]->durationMs)->toBe(20.0);
});

it('renders the dashboard for a permitted user with real numbers', function () {
    fakeTempo();
    $this->actingAs(tap(User::make()->email('admin@example.com')->makeSuper())->save());

    $this->get(cp_route('telemetry.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('statamic-telemetry::Telemetry')
            ->where('overview.recent.0.name', 'GET /')
            ->has('metrics'));
});

it('renders a single trace waterfall page', function () {
    fakeTempo();
    $this->actingAs(tap(User::make()->email('admin@example.com')->makeSuper())->save());

    $this->get(cp_route('telemetry.dashboard').'?trace=aaaa')
        ->assertOk()
        ->assertSee('Trace')
        ->assertSee('db.query');
});

it('shows the empty state when the backend has no traces, without throwing', function () {
    config()->set('statamic-telemetry.cp.audit.url', 'http://tempo.test');
    Http::fake(['http://tempo.test/*' => Http::response('boom', 500)]);
    $this->actingAs(tap(User::make()->email('admin@example.com')->makeSuper())->save());

    $this->get(cp_route('telemetry.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('statamic-telemetry::Telemetry')
            ->where('overview.count', 0));
});

it('denies the dashboard to a user without the permission', function () {
    $this->actingAs(tap(User::make()->email('nobody@example.com'))->save());

    $this->get(cp_route('telemetry.dashboard'))->assertRedirect();
});
