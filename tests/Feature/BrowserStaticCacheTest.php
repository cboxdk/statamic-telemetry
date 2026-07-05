<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\StaticCaching\BrowserTracingReplacer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Statamic\StaticCaching\Cacher;
use Statamic\StaticCaching\Cachers\FileCacher;

/**
 * A page as rendered with {{ telemetry:browser }} — the traceparent meta
 * and the per-request data-session are present.
 */
function pageHtml(): string
{
    return '<html><head>'
        .'<meta name="traceparent" content="00-abc123def4567890abc123def4567890-1122334455667788-01">'
        .'<script src="/telemetry/browser.js" defer data-endpoint="/telemetry/spans"'
        .' data-sample="1" data-analytics="1" data-session="sess-abcdef123456"></script>'
        .'</head><body>hi</body></html>';
}

test('the replacer strips the traceparent and data-session from the cached copy', function () {
    $replacer = new BrowserTracingReplacer;

    $cached = new Response(pageHtml());
    $initial = new Response(pageHtml());

    $replacer->prepareResponseToCache($cached, $initial);

    // The copy that gets cached must not leak either per-request value.
    expect($cached->getContent())->not->toContain('traceparent')
        ->and($cached->getContent())->not->toContain('data-session=')
        // …but the static RUM script itself survives.
        ->and($cached->getContent())->toContain('data-endpoint="/telemetry/spans"')
        ->and($cached->getContent())->toContain('/telemetry/browser.js');
});

test('the first (cache-warming) visitor keeps their traceparent', function () {
    $replacer = new BrowserTracingReplacer;

    $cached = new Response(pageHtml());
    $initial = new Response(pageHtml());

    $replacer->prepareResponseToCache($cached, $initial);

    // $initial is the response sent to the visitor who triggered the cache
    // write — they have a real server span, so their traceparent stays.
    expect($initial->getContent())->toContain('traceparent')
        ->and($initial->getContent())->toContain('data-session=');
});

test('replaceInCachedResponse is a no-op (self-rooting, not re-injection)', function () {
    $replacer = new BrowserTracingReplacer;

    $stripped = '<html><head><script src="/telemetry/browser.js" defer></script></head></html>';
    $response = new Response($stripped);

    $replacer->replaceInCachedResponse($response);

    expect($response->getContent())->toBe($stripped);
});

test('the replacer is registered in the static-cache replacer chain', function () {
    expect(config('statamic.static_caching.replacers'))->toContain(BrowserTracingReplacer::class);
});

test('full measure: the compiled HTML file is stripped, never the raw meta', function () {
    config()->set('statamic.static_caching.strategy', 'full');

    $cacher = app(Cacher::class);
    expect($cacher)->toBeInstanceOf(FileCacher::class);

    $request = Request::create('/browser-page');

    // Mirror the middleware: strip the clone, then compile it to the file.
    $cached = new Response(pageHtml());
    (new BrowserTracingReplacer)->prepareResponseToCache($cached, new Response(pageHtml()));

    $cacher->cachePage($request, $cached);

    // Read the compiled file back — future full-measure hits get exactly this,
    // served by the web server with no PHP in the loop.
    $file = $cacher->getCachedPage($request)->content;

    expect($file)->not->toContain('traceparent')
        ->and($file)->not->toContain('data-session=')
        ->and($file)->toContain('/telemetry/browser.js');
});
