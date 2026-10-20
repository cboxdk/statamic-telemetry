<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Tests\Integration;

use Cbox\StatamicTelemetry\Tests\TestCase;
use Cbox\TelemetryUi\TelemetryUiServiceProvider;

/**
 * The addon with the real cboxdk/laravel-telemetry-ui installed beside it —
 * for what depends on that package's own routes and names, which a faked
 * route cannot keep honest. Not a dependency (it needs PHP 8.4+, this addon
 * runs on 8.3): CI installs it where it can, and the tests skip without it.
 */
abstract class WithTelemetryUiTestCase extends TestCase
{
    public const MISSING = 'cboxdk/laravel-telemetry-ui is not installed (composer require --dev cboxdk/laravel-telemetry-ui).';

    public static function installed(): bool
    {
        return class_exists(TelemetryUiServiceProvider::class);
    }

    protected function getPackageProviders($app)
    {
        return [...parent::getPackageProviders($app), ...(self::installed() ? [TelemetryUiServiceProvider::class] : [])];
    }
}
