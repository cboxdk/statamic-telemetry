<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\StaticCaching\StaticCacheTelemetry;
use Cbox\StatamicTelemetry\StaticCaching\TracingApplicationCacher;
use Cbox\StatamicTelemetry\StaticCaching\TracingFileCacher;
use Cbox\Telemetry\Facades\Telemetry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Events\ResponsePrepared;
use Statamic\StaticCaching\Cacher;
use Statamic\StaticCaching\Cachers\ApplicationCacher;
use Statamic\StaticCaching\Cachers\FileCacher;

beforeEach(function () {
    StaticCacheTelemetry::consumePendingHeaderStrip();
});

test('the half strategy resolves to the tracing application cacher', function () {
    config()->set('statamic.static_caching.strategy', 'half');

    $cacher = app(Cacher::class);

    expect($cacher)->toBeInstanceOf(TracingApplicationCacher::class)
        ->and($cacher)->toBeInstanceOf(ApplicationCacher::class);
});

test('the full strategy resolves to the tracing file cacher', function () {
    config()->set('statamic.static_caching.strategy', 'full');

    $cacher = app(Cacher::class);

    expect($cacher)->toBeInstanceOf(TracingFileCacher::class)
        ->and($cacher)->toBeInstanceOf(FileCacher::class);
});

test('misses and writes are counted and put on the root span', function () {
    config()->set('statamic.static_caching.strategy', 'half');

    $fake = $this->fakeTelemetry();

    $span = Telemetry::span('GET /about');

    $cacher = app(Cacher::class);
    $request = Request::create('/about');

    $cacher->hasCachedPage($request);
    $cacher->cachePage($request, '<html>cached</html>');

    $span->end();
    $fake->flush();

    $fake->assertCounterIncremented('statamic.static_cache.operations', ['operation' => 'miss']);
    $fake->assertCounterIncremented('statamic.static_cache.operations', ['operation' => 'write']);

    $fake->assertSpanRecorded('GET /about', fn ($span) => $span->attributes()['statamic.static_cache'] === 'write');
});

test('cache hits are counted', function () {
    config()->set('statamic.static_caching.strategy', 'half');

    $fake = $this->fakeTelemetry();

    $cacher = app(Cacher::class);
    $request = Request::create('/about');

    $cacher->cachePage($request, '<html>cached</html>');
    event(new ResponsePrepared($request, new Response('<html>cached</html>')));

    $hitRequest = Request::create('/about');

    expect($cacher->hasCachedPage($hitRequest))->toBeTrue();

    $cacher->getCachedPage($hitRequest);

    $fake->assertCounterIncremented('statamic.static_cache.operations', ['operation' => 'hit']);
});

test('invalidations and flushes are counted', function () {
    config()->set('statamic.static_caching.strategy', 'half');

    $fake = $this->fakeTelemetry();

    $cacher = app(Cacher::class);

    $cacher->invalidateUrl('/about');
    $cacher->flush();

    $fake->assertCounterIncremented('statamic.static_cache.operations', ['operation' => 'invalidate']);
    $fake->assertCounterIncremented('statamic.static_cache.operations', ['operation' => 'flush']);
});

test('the trace id header is stripped before the application cacher snapshots headers', function () {
    config()->set('statamic.static_caching.strategy', 'half');

    $cacher = app(Cacher::class);
    $request = Request::create('/about');

    $cacher->cachePage($request, '<html>cached</html>');

    $response = new Response('<html>cached</html>');
    $response->headers->set('X-Trace-Id', 'abc123');
    $response->headers->set('Content-Type', 'text/html');

    event(new ResponsePrepared($request, $response));

    expect($response->headers->has('X-Trace-Id'))->toBeFalse();

    $page = $cacher->getCachedPage(Request::create('/about'));

    expect($page->headers)->not->toHaveKey('x-trace-id');
});

test('responses that are not being statically cached keep their trace id header', function () {
    $response = new Response('dynamic');
    $response->headers->set('X-Trace-Id', 'abc123');

    event(new ResponsePrepared(Request::create('/dynamic'), $response));

    expect($response->headers->has('X-Trace-Id'))->toBeTrue();
});
