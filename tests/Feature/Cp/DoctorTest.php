<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Diagnostics\Diagnostics;
use Cbox\Telemetry\Exporters\Spool\ArraySpool;
use Cbox\Telemetry\Exporters\Spool\Spool;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Statamic\Facades\User;

it('reports an unreachable backend without throwing', function () {
    config()->set('statamic-telemetry.cp.audit.url', 'http://loki.test');
    Http::fake(['http://loki.test/*' => Http::response('down', 500)]);

    $result = app(Diagnostics::class)->gather();

    expect($result->backendConfigured)->toBeTrue()
        ->and($result->backendReachable)->toBeFalse()
        ->and($result->backendDetail)->toContain('500');
});

it('reports a reachable backend when Loki answers', function () {
    config()->set('statamic-telemetry.cp.audit.url', 'http://loki.test');
    Http::fake(['http://loki.test/*' => Http::response(['status' => 'success', 'data' => []])]);
    // The spool the base package binds depends on the environment (Redis by
    // default — present on a dev machine, absent on CI); pin an in-memory one
    // so the probe's happy path is what is under test.
    app()->instance(Spool::class, new ArraySpool);

    $result = app(Diagnostics::class)->gather();

    expect($result->backendReachable)->toBeTrue()
        ->and($result->spoolSize)->toBe(0)
        ->and($result->spoolError)->toBeNull();
});

it('reports the backend as not configured when no url resolves', function () {
    config()->set('statamic-telemetry.cp.audit.url', null);

    $result = app(Diagnostics::class)->gather();

    expect($result->backendConfigured)->toBeFalse()
        ->and($result->backendReachable)->toBeNull();
});

it('renders the doctor page for a permitted user', function () {
    config()->set('statamic-telemetry.cp.audit.url', 'http://loki.test');
    Http::fake(['http://loki.test/*' => Http::response(['status' => 'success', 'data' => []])]);
    $this->actingAs(tap(User::make()->email('admin@example.com')->makeSuper())->save());

    $this->get(cp_route('telemetry.doctor'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('statamic-telemetry::Doctor')
            ->where('result.backendReachable', true)
            ->where('result.telemetryEnabled', true));
});

it('denies the doctor page to a user without the permission', function () {
    $this->actingAs(tap(User::make()->email('nobody@example.com'))->save());

    $this->get(cp_route('telemetry.doctor'))
        ->assertRedirect()
        ->assertDontSee('Telemetry doctor');
});

it('reports a spool it cannot read instead of failing the page', function () {
    config()->set('statamic-telemetry.cp.audit.url', null);
    app()->instance(Spool::class, new class implements Spool
    {
        public function push(array $entry): void {}

        public function pushMany(array $entries): void {}

        public function pop(int $count): array
        {
            return [];
        }

        public function requeue(array $entries): void {}

        public function size(): int
        {
            throw new RuntimeException('Connection refused');
        }
    });

    $result = app(Diagnostics::class)->gather();

    expect($result->spoolSize)->toBeNull()
        ->and($result->spoolError)->toBe('Connection refused');
});
