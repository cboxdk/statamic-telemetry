<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Cp\NavLink;
use Illuminate\Support\Facades\Route;

test('there is no nav link when nothing is configured and no telemetry-ui is installed', function () {
    config()->set('statamic-telemetry.cp.url', null);

    // laravel-telemetry-ui is not installed in the test env, so the
    // telemetry-ui routes do not exist and there is nothing to link.
    expect(NavLink::url())->toBeNull();
});

test('an explicit url wins over everything', function () {
    config()->set('statamic-telemetry.cp.url', 'https://grafana.example.com/d/cbox-tel-statamic');

    expect(NavLink::url())->toBe('https://grafana.example.com/d/cbox-tel-statamic');
});

test('a cross-origin url opens in a new tab; an in-app one does not', function () {
    // request()->getHost() is "localhost" in tests.
    expect(NavLink::opensInNewTab('https://grafana.example.com/d/x'))->toBeTrue()
        ->and(NavLink::opensInNewTab('http://localhost/telemetry-ui'))->toBeFalse()
        ->and(NavLink::opensInNewTab('/telemetry-ui'))->toBeFalse(); // relative
});

test('it points at the telemetry-ui dashboard on 2.x and later', function () {
    config()->set('statamic-telemetry.cp.url', null);

    // telemetry-ui 2.x+ serves every dashboard path from one SPA route; 1.x
    // had `telemetry-ui.page`. Only the old name was checked, so the nav
    // item vanished for everyone on 2.x and later.
    Route::get('/telemetry-ui/{any?}', fn () => 'spa')->name('telemetry-ui.spa');
    app('router')->getRoutes()->refreshNameLookups();

    expect(NavLink::url())->toBe('http://localhost/telemetry-ui');
});
