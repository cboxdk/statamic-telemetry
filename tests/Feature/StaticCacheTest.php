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

/**
 * Bind $request as the container's current request so it counts as the
 * request being served (StaticCacheTelemetry::isCurrentRequest).
 */
function serving(Request $request): Request
{
    app()->instance('request', $request);

    return $request;
}

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
    $request = serving(Request::create('/about'));

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

    $cacher->cachePage(serving(Request::create('/about')), '<html>cached</html>');
    event(new ResponsePrepared(app('request'), new Response('<html>cached</html>')));

    $hitRequest = serving(Request::create('/about'));

    expect($cacher->hasCachedPage($hitRequest))->toBeTrue();

    $cacher->getCachedPage($hitRequest);

    $fake->assertCounterIncremented('statamic.static_cache.operations', ['operation' => 'hit']);
});

test('a synthetic (non-current) request records nothing', function () {
    config()->set('statamic.static_caching.strategy', 'half');

    $fake = $this->fakeTelemetry();

    // The request being served is /real; Statamic probes the cache with a
    // separate synthetic request (an error-page copy, a warm job).
    serving(Request::create('/real'));

    $cacher = app(Cacher::class);
    $cacher->hasCachedPage(Request::create('/synthetic'));

    $fake->assertCounterNotIncremented('statamic.static_cache.operations');
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
    $request = serving(Request::create('/about'));

    $cacher->cachePage($request, '<html>cached</html>');

    $response = new Response('<html>cached</html>');
    $response->headers->set('X-Trace-Id', 'abc123');
    $response->headers->set('Content-Type', 'text/html');

    event(new ResponsePrepared($request, $response));

    expect($response->headers->has('X-Trace-Id'))->toBeFalse();

    $page = $cacher->getCachedPage(serving(Request::create('/about')));

    expect($page->headers)->not->toHaveKey('x-trace-id');
});

test('responses that are not being statically cached keep their trace id header', function () {
    $response = new Response('dynamic');
    $response->headers->set('X-Trace-Id', 'abc123');

    event(new ResponsePrepared(Request::create('/dynamic'), $response));

    expect($response->headers->has('X-Trace-Id'))->toBeTrue();
});

test('the pending header-strip flag does not leak between requests', function () {
    config()->set('statamic.static_caching.strategy', 'half');

    $cacher = app(Cacher::class);

    // Request A caches a page (flags itself) but never emits ResponsePrepared.
    $cacher->cachePage(serving(Request::create('/a')), '<html>a</html>');

    // Request B's response must keep its trace id — the flag was A's.
    $responseB = new Response('dynamic b');
    $responseB->headers->set('X-Trace-Id', 'b-trace');

    event(new ResponsePrepared(Request::create('/b'), $responseB));

    expect($responseB->headers->has('X-Trace-Id'))->toBeTrue();
});
