<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\ServiceProvider;
use Cbox\StatamicTelemetry\Tests\Integration\WithTelemetryUiTestCase;
use Statamic\CP\Navigation\Nav as Navigation;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\User;

test('the Telemetry item is in the built CP nav, under Tools, linking to the installed dashboard', function () {
    $this->actingAs(tap(User::make()->email('admin@example.com')->makeSuper())->save());

    // Statamic's AddonTestCase mocks Nav::build() to an empty collection. Put
    // a real nav back and register the addon's extension on it again, then
    // let Statamic build the nav the way the CP does.
    Nav::swap(new Navigation);
    (fn () => $this->bootCpNav())->call(app()->getProvider(ServiceProvider::class));

    $items = collect(Nav::build(false))->flatMap(fn (array $section) => collect($section['items'])
        ->mapWithKeys(fn ($item) => [$section['display'].' / '.$item->display() => $item->url()]));

    // The item went missing once already: telemetry-ui 2.0 renamed the route
    // it is built from, and a test against a faked route kept passing.
    expect($items)->toHaveKey('Tools / Telemetry')
        ->and($items['Tools / Telemetry'])->toBe('http://localhost/telemetry-ui');
})->skip(! WithTelemetryUiTestCase::installed(), WithTelemetryUiTestCase::MISSING);
