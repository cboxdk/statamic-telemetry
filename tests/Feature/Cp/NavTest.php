<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Cp\TelemetryNav;
use Cbox\StatamicTelemetry\ServiceProvider;
use Cbox\StatamicTelemetry\Telemetry\Logs;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Statamic\CP\Navigation\Nav as Navigation;
use Statamic\CP\Navigation\NavItem;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\User;

/**
 * Statamic's AddonTestCase mocks Nav::build() to an empty collection. Put a
 * real nav back, register the addon's extension on it again and let Statamic
 * build it the way the CP does, returning the Telemetry item.
 */
function builtTelemetryNavItem(): NavItem
{
    Nav::swap(new Navigation);
    (fn () => $this->bootCpNav())->call(app()->getProvider(ServiceProvider::class));

    $tools = collect(Nav::build(false))->firstWhere('display', 'Tools');

    return collect($tools['items'])->firstWhere(fn ($item) => $item->display() === 'Telemetry');
}

function superUser(): void
{
    test()->actingAs(tap(User::make()->email('admin@example.com')->makeSuper())->save());
}

it('puts Telemetry under Tools with every page as a child, Overview first', function () {
    superUser();

    $item = builtTelemetryNavItem();

    expect($item->url())->toBe(cp_route('telemetry.dashboard'))
        ->and($item->children()->map->display()->all())->toBe(['Overview', 'Explore', 'Content', 'Telemetry doctor']);
});

it('keeps the sidebar section active on every telemetry route, not only the dashboard', function () {
    superUser();
    config()->set('statamic-telemetry.cp.audit.url', 'http://backend.test');
    Http::fake(['http://backend.test/*' => Http::response(['traces' => []])]);

    // A nav item with children is active only on an exact URL match or an
    // active child; the Overview child's auto pattern covers /telemetry/*.
    $this->get(cp_route('telemetry.explore'))->assertOk();

    expect(builtTelemetryNavItem()->isActive())->toBeTrue();
});

it('builds the in-page nav from the user\'s permissions and marks the active page', function () {
    superUser();

    $items = app(TelemetryNav::class)->items('explore');

    expect(collect($items)->pluck('key')->all())->toContain('overview', 'explore', 'activity', 'static-cache', 'stache', 'glide', 'forms', 'content', 'doctor')
        ->and(collect($items)->firstWhere('key', 'explore')['active'])->toBeTrue()
        ->and(collect($items)->firstWhere('key', 'overview')['active'])->toBeFalse()
        ->and(collect($items)->firstWhere('key', 'activity')['params'])->toBe(['section' => 'activity']);
});

it('hides every in-page nav item from a user without telemetry permissions', function () {
    $this->actingAs(tap(User::make()->email('nobody@example.com'))->save());

    expect(app(TelemetryNav::class)->items('overview'))->toBe([]);
});

it('passes the in-page nav to every telemetry page', function () {
    superUser();
    config()->set('statamic-telemetry.cp.audit.url', 'http://backend.test');
    Http::fake(['http://backend.test/*' => Http::response(['traces' => []])]);

    foreach (['telemetry.dashboard' => 'overview', 'telemetry.explore' => 'explore', 'telemetry.doctor' => 'doctor'] as $route => $active) {
        $this->get(cp_route($route))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('nav')
                ->where('nav', fn ($nav) => collect($nav)->firstWhere('active', true)['key'] === $active));
    }
});

it('fetches a trace\'s log lines by trace id', function () {
    config()->set('statamic-telemetry.cp.audit.url', 'http://backend.test');
    config()->set('statamic-telemetry.cp.audit.service', 'cbox-web');
    Http::fake(['http://backend.test/loki/api/v1/query_range*' => Http::response(['status' => 'success', 'data' => [
        'resultType' => 'streams',
        'result' => [[
            'stream' => ['service_name' => 'cbox-web', 'level' => 'info'],
            'values' => [['1700000000000000000', 'cache miss', ['trace_id' => 'abc']]],
        ]],
    ]])]);

    $rows = app(Logs::class)->forTrace('abc');

    expect($rows)->toHaveCount(1)->and($rows[0]['traceId'])->toBe('abc');
    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), '{service_name="cbox-web"} | trace_id="abc"'));
});
