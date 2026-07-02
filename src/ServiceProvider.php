<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry;

use Cbox\StatamicTelemetry\Listeners\AddSiteContext;
use Cbox\StatamicTelemetry\Listeners\CaptureResponseData;
use Cbox\StatamicTelemetry\Listeners\RecordContentChange;
use Cbox\StatamicTelemetry\Listeners\RecordFormSubmission;
use Cbox\StatamicTelemetry\Listeners\RecordGlideGeneration;
use Cbox\StatamicTelemetry\Listeners\RecordStacheChange;
use Cbox\StatamicTelemetry\Listeners\StripTraceHeader;
use Cbox\StatamicTelemetry\Metrics\StatamicMetricsProvider;
use Cbox\StatamicTelemetry\StaticCaching\TracingApplicationCacher;
use Cbox\StatamicTelemetry\StaticCaching\TracingFileCacher;
use Cbox\StatamicTelemetry\View\TracingEngine;
use Cbox\Telemetry\Facades\Telemetry;
use Illuminate\Cache\Repository;
use Illuminate\Routing\Events\ResponsePrepared;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Cache;
use Statamic\Events;
use Statamic\Facades\StaticCache;
use Statamic\Providers\AddonServiceProvider;
use Statamic\StaticCaching\Cachers\Writer;

class ServiceProvider extends AddonServiceProvider
{
    protected $commands = [
        Console\DashboardsCommand::class,
    ];

    protected $listen = [
        Events\ResponseCreated::class => [CaptureResponseData::class],
        RouteMatched::class => [AddSiteContext::class],
        ResponsePrepared::class => [StripTraceHeader::class],
        Events\StacheWarmed::class => [RecordStacheChange::class],
        Events\StacheCleared::class => [RecordStacheChange::class],
        Events\GlideImageGenerated::class => [RecordGlideGeneration::class],
        Events\SubmissionCreated::class => [RecordFormSubmission::class],
        Events\EntrySaved::class => [RecordContentChange::class],
        Events\EntryDeleted::class => [RecordContentChange::class],
        Events\TermSaved::class => [RecordContentChange::class],
        Events\TermDeleted::class => [RecordContentChange::class],
        Events\AssetSaved::class => [RecordContentChange::class],
        Events\AssetDeleted::class => [RecordContentChange::class],
        Events\AssetUploaded::class => [RecordContentChange::class],
        Events\GlobalSetSaved::class => [RecordContentChange::class],
        Events\GlobalSetDeleted::class => [RecordContentChange::class],
        Events\NavSaved::class => [RecordContentChange::class],
        Events\NavDeleted::class => [RecordContentChange::class],
        Events\CollectionSaved::class => [RecordContentChange::class],
        Events\CollectionDeleted::class => [RecordContentChange::class],
        Events\TaxonomySaved::class => [RecordContentChange::class],
        Events\TaxonomyDeleted::class => [RecordContentChange::class],
        Events\FormSaved::class => [RecordContentChange::class],
        Events\FormDeleted::class => [RecordContentChange::class],
        Events\UserSaved::class => [RecordContentChange::class],
        Events\UserDeleted::class => [RecordContentChange::class],
        Events\BlueprintSaved::class => [RecordContentChange::class],
        Events\BlueprintDeleted::class => [RecordContentChange::class],
    ];

    public function bootAddon()
    {
        if (! config('statamic-telemetry.enabled', true)) {
            return;
        }

        Hooks::register();

        $this->bootStaticCacheDrivers();
        $this->bootViewEngine();
        $this->bootGauges();
    }

    /**
     * Swap the built-in static cache drivers for tracing subclasses.
     *
     * Subclasses — not a decorator — because Statamic's middleware and
     * replacers make instanceof checks against the concrete cachers
     * (FileCacher, ApplicationCacher) to pick code paths.
     */
    private function bootStaticCacheDrivers(): void
    {
        if (! config('statamic-telemetry.instrument.static_cache', true)) {
            return;
        }

        StaticCache::extend('file', function ($app, $config) {
            return new TracingFileCacher(
                new Writer($config['permissions'] ?? []),
                Cache::store(config()->has('cache.stores.static_cache') ? 'static_cache' : null),
                $config,
            );
        });

        StaticCache::extend('application', function ($app, $config) {
            return new TracingApplicationCacher($app[Repository::class], $config);
        });
    }

    /**
     * Wrap the Antlers view engine so every render becomes a detail span.
     * Runs in bootAddon — after all providers booted — so Statamic's own
     * engine registration is already in place.
     */
    private function bootViewEngine(): void
    {
        if (! config('statamic-telemetry.instrument.views', false)) {
            return;
        }

        $resolver = $this->app->make('view.engine.resolver');
        $inner = $resolver->resolve('antlers');

        $resolver->register('antlers', fn () => new TracingEngine($inner));
    }

    private function bootGauges(): void
    {
        if (! config('statamic-telemetry.gauges.enabled', false)) {
            return;
        }

        Telemetry::provider(new StatamicMetricsProvider);
    }
}
