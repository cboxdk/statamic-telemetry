<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Tests\Feature;

use Cbox\StatamicTelemetry\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

/**
 * The classifier is unit-tested key by key in CacheKeysTest; this covers
 * the two things that only show up once it is wired to the core cache
 * counter: that Hooks::register actually installs it, and that a
 * Stache-scale keyspace really does collapse to a handful of series
 * rather than one per key.
 */
class CacheKeyGroupsTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        // Cache counters are off by default in the core package, and the
        // instrumentation reads the flag at boot.
        $app['config']->set('telemetry.instrument.cache', true);
        $app['config']->set('cache.default', 'array');
    }

    public function test_a_stache_scale_keyspace_collapses_to_bounded_key_groups(): void
    {
        $fake = $this->fakeTelemetry();

        $keys = $this->keyspace();

        // Three operations per key: a cold read, a write, a warm read.
        foreach ($keys as $key) {
            Cache::get($key);
            Cache::put($key, 'value');
            Cache::get($key);
        }

        $operations = $fake->recordedMetrics('cache.operations');

        $operations
            ->assertLabelValues('key_group', [
                'stache.index',
                'stache.item',
                'stache.meta',
                'static_cache',
                'static_cache.nocache',
                'app',
            ])
            // Six groups x three operations x one store. Leaking the raw
            // key — or any per-collection/per-id fragment of it — into the
            // label turns this into hundreds of series.
            ->assertCardinalityBelow(25)
            ->assertLabelCardinalityBelow('key_group', 10);

        // Nothing was dropped: a classifier that returned null for a key
        // would silently lose that key's operations from the counter, and
        // the labelset would stop being comparable across stores.
        $this->assertSame(3.0 * count($keys), $operations->total());
    }

    /**
     * A warm Stache's cache traffic, at the shape (not the volume) a real
     * site produces: many ids under a few bounded prefixes.
     *
     * @return list<string>
     */
    private function keyspace(): array
    {
        $keys = ['stache::timing', 'stache::timestamps::entries', 'some-app-key'];

        foreach (['blog', 'pages', 'docs', 'events'] as $collection) {
            foreach (['title', 'slug', 'uri', 'published'] as $index) {
                $keys[] = "stache::indexes::collections::{$collection}::{$index}";
            }

            foreach (range(1, 10) as $id) {
                $keys[] = "stache::items::collections::{$collection}::entry-{$id}";
            }

            $keys[] = "static-cache:responses:{$collection}-index";
            $keys[] = "nocache::session.{$collection}";
        }

        return $keys;
    }
}
