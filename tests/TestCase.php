<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Tests;

use Cbox\StatamicTelemetry\Hooks;
use Cbox\StatamicTelemetry\ServiceProvider;
use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\TelemetryServiceProvider;
use Cbox\Telemetry\Testing\TelemetryFake;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), [
            TelemetryServiceProvider::class,
        ]);
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('telemetry.store', 'array');
        $app['config']->set('telemetry.exporters', []);
        $app['config']->set('telemetry.providers.system.enabled', false);

        $app['config']->set('view.paths', array_merge(
            [__DIR__.'/__fixtures__/views'],
            $app['config']->get('view.paths', []),
        ));
    }

    /**
     * Swap in the telemetry fake and re-register the addon's hooks on it —
     * the boot-time registrations landed on the manager the fake replaces.
     */
    protected function fakeTelemetry(): TelemetryFake
    {
        $fake = Telemetry::fake();

        Hooks::register($fake);

        return $fake;
    }
}
