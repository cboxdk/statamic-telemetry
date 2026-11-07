<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Integrations\Mcp\LifecycleObserver;
use Cbox\StatamicTelemetry\ServiceProvider;
use Cbox\StatamicTelemetry\Tests\TestCase;
use Cbox\Telemetry\Contracts\RedactsTelemetry;
use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Metrics\Registry;
use Cbox\Telemetry\Metrics\Stores\ArrayMetricStore;
use Cbox\Telemetry\Support\FailSafe;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Testing\CollectingExporter;
use Cbox\Telemetry\Tracing\SpanStatus;
use Cbox\Telemetry\Tracing\Tracer;
use Cboxdk\StatamicMcp\Actions\Events\ExecutionCompleted;
use Cboxdk\StatamicMcp\Actions\Events\ExecutionDenied;
use Cboxdk\StatamicMcp\Actions\Events\ExecutionFailed;
use Cboxdk\StatamicMcp\Actions\Events\ExecutionReplayed;
use Cboxdk\StatamicMcp\Actions\Events\ExecutionStarted;
use Cboxdk\StatamicMcp\Actions\Events\GrantIssued;
use Cboxdk\StatamicMcp\Actions\ObserverRegistry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

uses(TestCase::class);

beforeEach(function () {
    if (! interface_exists(Cboxdk\StatamicMcp\Actions\Contracts\LifecycleObserver::class)) {
        $this->markTestSkipped('Optional statamic-mcp v4 is not installed.');
    }
    config()->set('statamic-telemetry.instrument.mcp', true);
    config()->set('statamic-telemetry.mcp.flush_stdio', false);
});

test('real content spans are children of the execution and HTTP parent survives', function () {
    Collection::make('pages')->save();
    $fake = $this->fakeTelemetry();
    $parent = $fake->tracer()->startSpan('HTTP request');
    $observer = new LifecycleObserver;
    $execution = (string) Str::uuid();
    $observer->observe(new ExecutionStarted('start', 'private-operation', 'fixture/addon/edit', 1, 'http', 'started', 'none', 0, gmdate('c'), executionId: $execution));
    $executionSpan = $fake->tracer()->currentSpan();
    Entry::make()->collection('pages')->slug('draft')->published(false)->data(['title' => 'private title'])->save();
    $observer->observe(new ExecutionCompleted('end', 'private-operation', 'fixture/addon/edit', 1, 'http', 'completed', 'committed', 15, gmdate('c'), executionId: $execution));
    expect($fake->tracer()->currentSpan())->toBe($parent);
    $content = $fake->recordedSpans('statamic.content.change');
    expect($content)->not->toBeEmpty();
    expect($content[0]->parentSpanId)->toBe($executionSpan->spanId);
    $spans = $fake->recordedSpans('statamic.mcp.execute');
    expect($spans)->toHaveCount(1);
    expect($spans[0]->parentSpanId)->toBe($parent->spanId);
    expect(json_encode($spans[0]->attributes()))->not->toContain('private-operation', 'private title');
    expect($spans[0]->status())->toBe(SpanStatus::Ok);
    $parent->end();
});

test('stdio attempts clear ambient identity and use independent traces without stdout', function () {
    $fake = $this->fakeTelemetry();
    $observer = new LifecycleObserver;
    $fake->tracer()->addContext(['user.secret' => 'previous actor']);
    ob_start();
    try {
        foreach (['first', 'second'] as $id) {
            $observer->observe(new ExecutionStarted($id.'-start', 'operation', 'fixture/addon/read', 1, 'stdio', 'started', 'none', 0, gmdate('c'), executionId: $id));
            $observer->observe(new ExecutionCompleted($id.'-end', 'operation', 'fixture/addon/read', 1, 'stdio', 'completed', 'none', 2, gmdate('c'), executionId: $id));
            expect($fake->tracer()->currentSpan())->toBeNull();
            expect($fake->tracer()->contextAttributes())->toBe([]);
        }
        expect(ob_get_contents())->toBe('');
    } finally {
        ob_end_clean();
    }
    $spans = $fake->recordedSpans('statamic.mcp.execute');
    expect($spans)->toHaveCount(2);
    expect($spans[0]->traceId)->not->toBe($spans[1]->traceId);
    expect(json_encode(array_map(fn ($span) => $span->attributes(), $spans)))->not->toContain('previous actor');
});

test('denial replay and failed execution stay distinct and identifiers never label metrics', function () {
    $fake = $this->fakeTelemetry();
    $observer = new LifecycleObserver;
    $observer->observe(new ExecutionDenied('denied', 'private-operation', 'fixture/addon/edit', 1, 'http', 'denied', 'none', 2, gmdate('c'), executionId: 'denied-call'));
    $observer->observe(new ExecutionReplayed('replayed', 'private-operation', 'fixture/addon/edit', 1, 'http', 'replayed', 'none', 2, gmdate('c'), executionId: 'retry'));
    $observer->observe(new ExecutionStarted('start', 'private-operation', 'fixture/addon/edit', 1, 'http', 'started', 'none', 0, gmdate('c'), executionId: 'failed-call'));
    $observer->observe(new ExecutionFailed('failed', 'private-operation', 'fixture/addon/edit', 1, 'http', 'failed', 'unknown', 3, gmdate('c'), executionId: 'failed-call'));
    $grant = new GrantIssued('grant-event', 'private-grant', 'http', gmdate('c'));
    $observer->observe($grant);
    $observer->observe($grant);
    expect($fake->recordedSpans('statamic.mcp.execute'))->toHaveCount(1);
    expect($fake->recordedSpans('statamic.mcp.execute')[0]->status())->toBe(SpanStatus::Error);
    expect($fake->counterValue('statamic.mcp.grants', ['event' => 'grant.issued', 'transport' => 'http']))->toBe(1.0);
    foreach (['denied' => 'none', 'replayed' => 'none', 'failed' => 'unknown'] as $outcome => $effects) {
        expect($fake->counterValue('statamic.mcp.calls', ['action' => 'fixture/addon/edit', 'transport' => 'http', 'outcome' => $outcome, 'effects' => $effects]))->toBe(1.0);
    }
});

test('disabled instrumentation never resolves telemetry and discards an open span', function () {
    $fake = $this->fakeTelemetry();
    $observer = new LifecycleObserver;
    $observer->observe(new ExecutionStarted('start', 'op', 'fixture/addon/read', 1, 'http', 'started', 'none', 0, gmdate('c'), executionId: 'call'));
    config()->set('statamic-telemetry.instrument.mcp', false);
    $observer->observe(new ExecutionCompleted('end', 'op', 'fixture/addon/read', 1, 'http', 'completed', 'none', 2, gmdate('c'), executionId: 'call'));
    expect($fake->tracer()->currentSpan())->toBeNull();
    expect($fake->recordedSpans('statamic.mcp.execute'))->toBe([]);
    Telemetry::clearResolvedInstance(TelemetryManager::class);
    app()->forgetInstance(TelemetryManager::class);
    app()->bind(TelemetryManager::class, fn () => throw new RuntimeException('Disabled integration resolved telemetry.'));
    $observer->observe(new GrantIssued('ignored', 'grant', 'http', gmdate('c')));
});

test('redactor failure drops MCP spans before a real exporter sees them', function () {
    $redactor = Mockery::mock(RedactsTelemetry::class);
    $redactor->shouldReceive('spans')->once()->andThrow(new RuntimeException('private redactor detail'));
    $redactor->shouldReceive('events')->andReturn([]);
    $manager = new TelemetryManager(true,
        new Registry(new ArrayMetricStore, [0.001, 0.01, 0.1, 1.0, 10.0]),
        new Tracer, redactor: $redactor);
    $exporter = new CollectingExporter;
    $manager->addExporter($exporter);
    Telemetry::swap($manager);
    $observer = new LifecycleObserver;
    FailSafe::handleExceptionsUsing(static fn () => null);
    try {
        $observer->observe(new ExecutionStarted('start', 'secret-operation', 'fixture/addon/read', 1, 'http', 'started', 'none', 0, gmdate('c'), executionId: 'call'));
        $observer->observe(new ExecutionCompleted('end', 'secret-operation', 'fixture/addon/read', 1, 'http', 'completed', 'none', 2, gmdate('c'), executionId: 'call'));
        expect($manager->tracer()->bufferedCount())->toBe(1);
        $manager->flush();
        expect($manager->tracer()->bufferedCount())->toBe(0);
        expect($exporter->batches())->toBe([]);
        expect($manager->tracer()->currentSpan())->toBeNull();
    } finally {
        FailSafe::handleExceptionsUsing(null);
    }
});

test('no configured adapter means no registry or telemetry resolution', function () {
    config()->set('statamic-telemetry.instrument.mcp', false);
    app()->bind(ObserverRegistry::class, fn () => throw new RuntimeException('Registry should not be resolved'));
    $provider = new ServiceProvider(app());
    (new ReflectionMethod($provider, 'bootMcp'))->invoke($provider);
    config()->set('statamic-telemetry.instrument.mcp', true);
    app()->offsetUnset(ObserverRegistry::class);
    (new ReflectionMethod($provider, 'bootMcp'))->invoke($provider);
    expect(app()->bound(ObserverRegistry::class))->toBeFalse();
});

test('late observer registration and failed logging do not break application boot', function () {
    $registry = new ObserverRegistry;
    $registry->freeze();
    app()->instance(ObserverRegistry::class, $registry);
    Log::shouldReceive('warning')->once()->andThrow(new RuntimeException('Logger unavailable'));
    (new ReflectionMethod(new ServiceProvider(app()), 'bootMcp'))->invoke(new ServiceProvider(app()));
    expect($registry->classes())->toBe([]);
});
