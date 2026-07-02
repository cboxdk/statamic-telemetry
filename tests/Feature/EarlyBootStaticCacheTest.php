<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Tests\Feature;

use Cbox\StatamicTelemetry\StaticCaching\TracingApplicationCacher;
use Cbox\StatamicTelemetry\Tests\TestCase;
use Statamic\StaticCaching\Cacher;

/**
 * Regression: with a strategy configured at boot (the real-app case),
 * Statamic's own boot subscribes the invalidator, which resolves and
 * caches the driver before any booted() callback runs. The driver swap
 * must therefore happen as soon as the manager exists — extending from
 * bootAddon comes too late and yields the untraced cacher.
 */
class EarlyBootStaticCacheTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic.static_caching.strategy', 'half');
    }

    public function test_the_driver_swap_beats_statamics_boot_time_cacher_resolution(): void
    {
        // Mirror the boot-time resolution the invalidator subscription
        // triggers, then assert the swap already won.
        $this->assertInstanceOf(TracingApplicationCacher::class, $this->app->make(Cacher::class));
    }
}
