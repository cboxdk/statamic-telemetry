<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Tests\Integration;

use Cbox\StatamicTelemetry\Tests\TestCase;
use Cbox\TelemetryUi\TelemetryUiServiceProvider;

/**
 * The addon with the real cboxdk/laravel-telemetry-ui installed beside it —
 * for what depends on that package's own routes and names, which a faked
 * route cannot keep honest.
 */
abstract class WithTelemetryUiTestCase extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [...parent::getPackageProviders($app), TelemetryUiServiceProvider::class];
    }
}
