<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Support\Content;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Http\Controllers\FrontendController;

test('a frontend entry request gets a bounded span name and entry attributes', function () {
    Collection::make('pages')->routes('{slug}')->save();

    $entry = tap(Entry::make()->collection('pages')->slug('about')->data(['title' => 'About']))->save();

    $fake = $this->fakeTelemetry();

    $this->get('/about')->assertOk();

    $spans = array_filter(
        $fake->recordedSpans(),
        fn ($span) => str_starts_with($span->name, 'GET entry:pages'),
    );

    expect($spans)->toHaveCount(1);

    $span = array_values($spans)[0];

    expect($span->name)->toBe('GET entry:pages.page')
        ->and($span->attributes()['statamic.collection'])->toBe('pages')
        ->and($span->attributes()['statamic.entry.id'])->toBe((string) $entry->id())
        ->and($span->attributes()['statamic.type'])->toBe('entry')
        // http.route is the logical route; the raw catch-all is preserved.
        ->and($span->attributes()['http.route'])->toBe('entry:pages.page')
        ->and($span->attributes()['http.route.template'])->toBe('/{segments?}');
});

test('the request duration metric http.route is the logical content route', function () {
    Collection::make('pages')->routes('{slug}')->save();
    tap(Entry::make()->collection('pages')->slug('about')->data(['title' => 'About']))->save();

    $fake = $this->fakeTelemetry();

    $this->get('/about')->assertOk();

    // http.route now carries the logical content route (via the core
    // resolveRouteUsing hook), so route tables and histograms group by it
    // instead of the /{segments?} catch-all.
    $routes = [];
    foreach ($fake->collect() as $family) {
        if ($family->name() === 'http.server.request.duration') {
            foreach ($family->samples as $sample) {
                $routes[] = $sample->labels['http.route'] ?? null;
            }
        }
    }

    expect($routes)->toContain('entry:pages.page')
        ->and($routes)->not->toContain('/{segments?}');
});

test('a Statamic frontend 404 is bucketed as not_found', function () {
    $fake = $this->fakeTelemetry();

    $this->get('/definitely-missing')->assertNotFound();

    $routes = [];
    foreach ($fake->collect() as $family) {
        if ($family->name() === 'http.server.request.duration') {
            foreach ($family->samples as $sample) {
                $routes[] = $sample->labels['http.route'] ?? null;
            }
        }
    }

    // 404 traffic (broken links, bots) gets its own bounded bucket instead
    // of polluting the /{segments?} catch-all.
    expect($routes)->toContain('not_found')
        ->and($routes)->not->toContain('/{segments?}');

    // The raw catch-all template is still preserved on the span.
    $span = array_values(array_filter(
        $fake->recordedSpans(),
        fn ($s) => $s->name === 'GET not_found',
    ))[0];

    expect($span->attributes()['http.route.template'])->toBe('/{segments?}');
});

test('only a frontend 404 becomes not_found — a real route keeps its own', function () {
    $notFound = new Response('', 404);

    // A request routed through Statamic's frontend catch-all. The Router
    // populates the 'controller' action key at dispatch; a hand-built Route
    // needs it set explicitly for getActionName() to reflect the controller.
    $frontend = Request::create('/missing');
    $frontend->setRouteResolver(fn () => new Route(
        ['GET'], '/{segments?}', ['controller' => FrontendController::class.'@index'],
    ));

    // A request routed to a normal controller.
    $real = Request::create('/api/thing');
    $real->setRouteResolver(fn () => new Route(
        ['GET'], '/api/thing', ['controller' => 'App\Http\Controllers\ThingController@show'],
    ));

    expect(Content::route($frontend, $notFound))->toBe('not_found')
        ->and(Content::route($real, $notFound))->toBeNull();
});

test('entries in structured collections are unwrapped from their page object', function () {
    // The default statamic/statamic skeleton's pages collection: structured
    // with a tree — ResponseCreated then carries a Structures\Page wrapper,
    // not the entry itself. Regression from the live demo.
    $collection = Collection::make('docs')->routes('{parent_uri}/{slug}')->structureContents(['root' => true]);
    $collection->save();

    $entry = tap(Entry::make()->collection('docs')->slug('guide')->data(['title' => 'Guide']))->save();

    $collection->structure()->makeTree(Site::default()->handle())
        ->tree([['entry' => $entry->id()]])->save();

    $fake = $this->fakeTelemetry();

    // With root: true the first tree entry is the site root.
    $this->get('/')->assertOk();

    $spans = array_filter(
        $fake->recordedSpans(),
        fn ($span) => str_starts_with($span->name, 'GET entry:docs'),
    );

    expect($spans)->toHaveCount(1);

    $span = array_values($spans)[0];

    expect($span->attributes()['statamic.collection'])->toBe('docs')
        ->and($span->attributes()['statamic.entry.id'])->toBe((string) $entry->id());
});

test('a 404 is never named as content', function () {
    $fake = $this->fakeTelemetry();

    $this->get('/definitely-missing-page')->assertNotFound();

    $named = array_filter(
        $fake->recordedSpans(),
        fn ($span) => str_starts_with($span->name, 'GET entry:'),
    );

    expect($named)->toBeEmpty();
});
