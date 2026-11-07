<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Tests\Integration\Mcp;

use Cbox\StatamicTelemetry\Integrations\Mcp\LifecycleObserver;
use Cbox\StatamicTelemetry\Tests\Fixtures\McpFixtureProvider;
use Cbox\StatamicTelemetry\Tests\TestCase;
use Cbox\Telemetry\Contracts\Exporter;
use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Metrics\Registry;
use Cbox\Telemetry\Metrics\Stores\ArrayMetricStore;
use Cbox\Telemetry\Support\ExportResult;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\Support\SignalSet;
use Cbox\Telemetry\Support\TelemetryBatch;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Tracer;
use Cboxdk\StatamicMcp\Actions\ActionExecutor;
use Cboxdk\StatamicMcp\Actions\CapabilityRegistry;
use Cboxdk\StatamicMcp\Actions\ExecutionContext;
use Cboxdk\StatamicMcp\Actions\ObserverRegistry;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Server\McpServiceProvider;
use Statamic\Events\EntrySaved;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

final class ExecutionIntegrationTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        $providers = parent::getPackageProviders($app);
        if (class_exists(ServiceProvider::class)) {
            require_once __DIR__.'/../../Fixtures/McpActions.php';
            $providers[] = McpServiceProvider::class;
            $providers[] = ServiceProvider::class;
            $providers[] = McpFixtureProvider::class;
        }

        return $providers;
    }

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('statamic-telemetry.instrument.mcp', true);
        $app['config']->set('statamic.mcp.profiles.stdio', 'operator');
        $app['config']->set('statamic.mcp.storage.operations_path', sys_get_temp_dir().'/mcp-telemetry-'.bin2hex(random_bytes(8)));
        $app['config']->set('statamic.mcp.storage.tokens_path', sys_get_temp_dir().'/mcp-telemetry-tokens-'.bin2hex(random_bytes(8)));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config('statamic.mcp.storage.operations_path'));
        File::deleteDirectory(config('statamic.mcp.storage.tokens_path'));
        FailSafe::handleExceptionsUsing(null);
        parent::tearDown();
    }

    public function test_throwing_exporter_does_not_change_native_write_or_replay_it(): void
    {
        if (! class_exists(ActionExecutor::class)) {
            $this->markTestSkipped('Optional statamic-mcp v4 is not installed.');
        }
        $this->assertContains(LifecycleObserver::class, app(ObserverRegistry::class)->classes());
        $this->assertContains('mcp:start', config('telemetry.native.exclude_commands'));
        Collection::make('pages')->save();
        $entry = Entry::make()->collection('pages')->slug('draft')->published(false)->data(['title' => 'Before']);
        $entry->save();
        $user = User::make()->email('mcp@example.test')->makeSuper();
        $user->save();
        $grant = app(CapabilityRegistry::class)->forScopes(['fixture:edit']);
        $token = app(TokenService::class)->createToken($user->id(), 'Integration', ['fixture:edit'], capabilities: $grant)['model'];
        $manager = new TelemetryManager(true, new Registry(new ArrayMetricStore, [0.001, 0.01, 0.1, 1.0, 10.0]), new Tracer);
        $exporter = new class implements Exporter
        {
            public int $calls = 0;

            public function name(): string
            {
                return 'failing-test';
            }

            public function supports(): SignalSet
            {
                return SignalSet::all();
            }

            public function export(TelemetryBatch $batch): ExportResult
            {
                $this->calls++;
                throw new \RuntimeException('Exporter unavailable');
            }
        };
        $manager->addExporter($exporter);
        Telemetry::swap($manager);
        FailSafe::handleExceptionsUsing(static fn () => null);
        $context = new ExecutionContext($user->id(), $grant->grantId, 'default', 'operator', 'stdio', 'prepare', hrtime(true) + 60_000_000_000, $token->id);
        $input = ['id' => $entry->id(), 'title' => 'After'];
        $executor = app(ActionExecutor::class);
        $operation = $executor->prepare('fixture/telemetry/edit', $input, $context);
        $context = $context->withOperationId($operation->id);
        $writes = 0;
        Event::listen(EntrySaved::class, function () use (&$writes): void {
            $writes++;
        });
        $this->assertSame(['id' => $entry->id()], $executor->execute('fixture/telemetry/edit', $input, $context));
        $this->assertSame(['id' => $entry->id()], $executor->execute('fixture/telemetry/edit', $input, $context));
        $this->assertSame(1, $writes);
        $this->assertSame('After', Entry::find($entry->id())->get('title'));
        $this->assertGreaterThan(0, $exporter->calls);
        $this->assertNull($manager->tracer()->currentSpan());
    }
}
