<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry;

use Cbox\StatamicTelemetry\Listeners\AddSiteContext;
use Cbox\StatamicTelemetry\Listeners\CaptureResponseData;
use Cbox\StatamicTelemetry\Listeners\RecordAuthEvent;
use Cbox\StatamicTelemetry\Listeners\RecordContentChange;
use Cbox\StatamicTelemetry\Listeners\RecordFormSubmission;
use Cbox\StatamicTelemetry\Listeners\RecordGlideCacheClear;
use Cbox\StatamicTelemetry\Listeners\RecordGlideGeneration;
use Cbox\StatamicTelemetry\Listeners\RecordSearchIndexUpdate;
use Cbox\StatamicTelemetry\Listeners\RecordStacheChange;
use Cbox\StatamicTelemetry\Listeners\StripTraceHeader;
use Cbox\StatamicTelemetry\Metrics\StatamicMetricsProvider;
use Cbox\StatamicTelemetry\StaticCaching\TracingApplicationCacher;
use Cbox\StatamicTelemetry\StaticCaching\TracingFileCacher;
use Cbox\StatamicTelemetry\Support\TracingBlink;
use Cbox\StatamicTelemetry\View\AntlersNodeTracer;
use Cbox\StatamicTelemetry\View\TracingEngine;
use Cbox\Telemetry\Facades\Telemetry;
use Illuminate\Cache\Repository;
use Illuminate\Routing\Events\ResponsePrepared;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Cache;
use Statamic\Events;
use Statamic\Providers\AddonServiceProvider;
use Statamic\StaticCaching\Cachers\Writer;
use Statamic\StaticCaching\StaticCacheManager;
use Statamic\Support\Blink;

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
        Events\GlideCacheCleared::class => [RecordGlideCacheClear::class],
        Events\GlideAssetCacheCleared::class => [RecordGlideCacheClear::class],
        Events\SearchIndexUpdated::class => [RecordSearchIndexUpdate::class],
        Events\ImpersonationStarted::class => [RecordAuthEvent::class],
        Events\ImpersonationEnded::class => [RecordAuthEvent::class],
        Events\UserRegistered::class => [RecordAuthEvent::class],
        Events\UserPasswordChanged::class => [RecordAuthEvent::class],
        Events\TwoFactorAuthenticationEnabled::class => [RecordAuthEvent::class],
        Events\TwoFactorAuthenticationDisabled::class => [RecordAuthEvent::class],
        Events\TwoFactorAuthenticationChallenged::class => [RecordAuthEvent::class],
        Events\TwoFactorAuthenticationFailed::class => [RecordAuthEvent::class],
        Events\ValidTwoFactorAuthenticationCodeProvided::class => [RecordAuthEvent::class],
        Events\TwoFactorRecoveryCodeReplaced::class => [RecordAuthEvent::class],
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
        Events\RevisionSaved::class => [RecordContentChange::class],
        Events\RevisionDeleted::class => [RecordContentChange::class],
        Events\LocalizedTermSaved::class => [RecordContentChange::class],
        Events\LocalizedTermDeleted::class => [RecordContentChange::class],
        Events\GlobalVariablesSaved::class => [RecordContentChange::class],
        Events\GlobalVariablesDeleted::class => [RecordContentChange::class],
        Events\NavTreeSaved::class => [RecordContentChange::class],
        Events\NavTreeDeleted::class => [RecordContentChange::class],
        Events\CollectionTreeSaved::class => [RecordContentChange::class],
        Events\CollectionTreeDeleted::class => [RecordContentChange::class],
        Events\AssetReplaced::class => [RecordContentChange::class],
        Events\AssetReuploaded::class => [RecordContentChange::class],
        Events\AssetReferencesUpdated::class => [RecordContentChange::class],
        Events\TermReferencesUpdated::class => [RecordContentChange::class],
        Events\AssetFolderSaved::class => [RecordContentChange::class],
        Events\AssetFolderDeleted::class => [RecordContentChange::class],
        Events\AssetContainerSaved::class => [RecordContentChange::class],
        Events\AssetContainerDeleted::class => [RecordContentChange::class],
        Events\SiteSaved::class => [RecordContentChange::class],
        Events\SiteDeleted::class => [RecordContentChange::class],
        Events\RoleSaved::class => [RecordContentChange::class],
        Events\RoleDeleted::class => [RecordContentChange::class],
        Events\UserGroupSaved::class => [RecordContentChange::class],
        Events\UserGroupDeleted::class => [RecordContentChange::class],
        Events\FieldsetSaved::class => [RecordContentChange::class],
        Events\FieldsetDeleted::class => [RecordContentChange::class],
        Events\SubmissionDeleted::class => [RecordContentChange::class],
        Events\EntryScheduleReached::class => [RecordContentChange::class],
        Events\DuplicateIdRegenerated::class => [RecordContentChange::class],
    ];

    public function register()
    {
        // The addon convention merges config in boot, but the static cache
        // driver swap below needs the toggles during register already.
        $this->mergeConfigFrom(__DIR__.'/../config/statamic-telemetry.php', 'statamic-telemetry');

        $this->registerStaticCacheDrivers();
        $this->registerTracingBlink();
    }

    /**
     * Blink is resolved through the container by its facade; binding the
     * tallying subclass makes every store count once() hits/misses onto
     * the trace root span. Register phase — before anything memoizes.
     */
    private function registerTracingBlink(): void
    {
        if (! config('statamic-telemetry.enabled', true)
            || ! config('statamic-telemetry.instrument.blink', true)) {
            return;
        }

        $this->app->singleton(Blink::class, TracingBlink::class);
    }

    public function bootAddon()
    {
        if (! config('statamic-telemetry.enabled', true)) {
            return;
        }

        Hooks::register();

        $this->bootViewEngine();
        $this->bootAntlersTracer();
        $this->bootGauges();
    }

    /**
     * Tag-level render spans via Statamic's official Antlers runtime
     * tracing hook. Opt-in: the runtime only consults tracers when
     * statamic.antlers.tracing is on, and per-node tracing has a cost.
     * The parser binding reads this config at resolve time (per render),
     * so setting it in boot is early enough.
     */
    private function bootAntlersTracer(): void
    {
        if (! config('statamic-telemetry.instrument.antlers', false)) {
            return;
        }

        config()->set('statamic.antlers.tracing', true);
        config()->set('statamic.antlers.tracers', array_merge(
            config('statamic.antlers.tracers', []) ?? [],
            [AntlersNodeTracer::class],
        ));
    }

    /**
     * Swap the built-in static cache drivers for tracing subclasses.
     *
     * Subclasses — not a decorator — because Statamic's middleware and
     * replacers make instanceof checks against the concrete cachers
     * (FileCacher, ApplicationCacher) to pick code paths.
     *
     * Registered via afterResolving on the manager, not in bootAddon:
     * with static caching enabled, Statamic's own boot subscribes the
     * invalidator, which resolves (and caches) the driver before any
     * booted() callback runs. Extending as soon as the manager exists
     * always beats the first driver() call.
     */
    private function registerStaticCacheDrivers(): void
    {
        $extend = function (StaticCacheManager $manager): void {
            if (! config('statamic-telemetry.enabled', true)
                || ! config('statamic-telemetry.instrument.static_cache', true)) {
                return;
            }

            $manager->extend('file', function ($app, $config) {
                return new TracingFileCacher(
                    new Writer($config['permissions'] ?? []),
                    Cache::store(config()->has('cache.stores.static_cache') ? 'static_cache' : null),
                    $config,
                );
            });

            $manager->extend('application', function ($app, $config) {
                return new TracingApplicationCacher($app[Repository::class], $config);
            });
        };

        $this->app->afterResolving(StaticCacheManager::class, $extend);

        if ($this->app->resolved(StaticCacheManager::class)) {
            $extend($this->app->make(StaticCacheManager::class));
        }
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
