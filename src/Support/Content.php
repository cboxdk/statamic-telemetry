<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Support;

use Illuminate\Http\Request;
use Statamic\Entries\Entry;
use Statamic\Facades\Data;
use Statamic\Facades\Site;
use Statamic\Http\Controllers\FrontendController;
use Statamic\Http\Controllers\GlideController;
use Statamic\Structures\Page;
use Statamic\Taxonomies\LocalizedTerm;
use Statamic\Taxonomies\Taxonomy;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Derives the root span name and attributes from the content Statamic served.
 *
 * All frontend requests share one catch-all route, so the default
 * "METHOD /route/{pattern}" name collapses to a single value. The
 * ResponseCreated listener stashes the resolved data on the request; the
 * resolvers read it back at terminate. A static-cache hit answers before
 * the controller resolves anything, so for those the content is looked up
 * by URL instead — the page is the same page whether it came from cache or
 * not, and the CP should say so. Names stay bounded: collection and
 * blueprint handles, never ids or slugs; the specifics (title, url, edit
 * url) ride along as span attributes.
 */
final class Content
{
    public const REQUEST_ATTRIBUTE = 'statamic_telemetry_data';

    /** Set once the URL lookup ran, so the three resolvers share one Stache hit. */
    private const LOOKED_UP = 'statamic_telemetry_looked_up';

    public static function capture(Request $request, mixed $data): void
    {
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $data);
    }

    public static function spanName(Request $request): ?string
    {
        $label = self::descriptor($request)['label'] ?? null;

        return $label === null ? null : $request->method().' '.$label;
    }

    /**
     * A bounded route dimension for metric labels — `entry:{collection}.
     * {blueprint}` / `term:{taxonomy}`, or null for non-content requests.
     *
     * The base package's `http.route` metric label is the literal route
     * template (`/{segments?}`), which collapses every frontend page into
     * one series. This is the per-content dimension to group by instead;
     * it is bounded (a fixed set of collections and taxonomies), which is
     * why the addon — not the base package — is the right place to emit
     * it: only the addon knows these names are bounded.
     */
    public static function routeLabel(Request $request): ?string
    {
        return self::descriptor($request)['label'] ?? null;
    }

    /**
     * The logical route for a Statamic request, given the final response:
     * the content route when one resolved (or was found at the URL — cache
     * hits included), `glide:{container}` for image generation, else
     * `not_found` for a frontend 404 (a URL that matched no entry/term/
     * taxonomy: broken links, bots, stale sitemaps). Returns null for
     * anything else — the base keeps their route.
     */
    public static function route(Request $request, Response $response): ?string
    {
        $label = self::routeLabel($request);

        if ($label !== null) {
            return $label;
        }

        if (self::isGlideRequest($request)) {
            return 'glide:'.self::glideSource($request);
        }

        if ($response->getStatusCode() === 404 && self::isFrontendRequest($request)) {
            return 'not_found';
        }

        return null;
    }

    /**
     * Was this request handled by Statamic's frontend catch-all
     * (FrontendController), versus a real Laravel route or the CP? Guards
     * the URL lookup and the `not_found` fallback so a 404 on a genuine
     * route keeps its own `http.route`.
     */
    private static function isFrontendRequest(Request $request): bool
    {
        return self::handledBy($request, FrontendController::class);
    }

    private static function isGlideRequest(Request $request): bool
    {
        return self::handledBy($request, GlideController::class);
    }

    private static function handledBy(Request $request, string $controller): bool
    {
        $route = $request->route();

        return $route !== null && str_contains((string) $route->getActionName(), $controller);
    }

    /** The asset container a Glide URL renders from, or `path` / `url` for the other two routes. */
    private static function glideSource(Request $request): string
    {
        $container = $request->route('container');

        if (is_string($container) && $container !== '') {
            return $container;
        }

        return str_contains((string) $request->route()?->getActionName(), 'generateByUrl') ? 'url' : 'path';
    }

    /**
     * @return array<string, scalar|null>
     */
    public static function attributes(Request $request): array
    {
        $attributes = self::descriptor($request)['attributes'] ?? [];

        if ($attributes === [] && self::isGlideRequest($request)) {
            $preset = $request->query('p');
            $attributes = array_filter([
                'statamic.type' => 'glide',
                'statamic.asset.container' => is_string($c = $request->route('container')) ? $c : null,
                'statamic.glide.preset' => is_string($preset) && $preset !== '' ? $preset : null,
            ], fn ($value) => $value !== null);
        }

        if (config('statamic-telemetry.instrument.site_context', true)) {
            $attributes['statamic.site'] ??= Site::current()->handle();
        }

        return $attributes;
    }

    /**
     * @return array{label: string, attributes: array<string, scalar|null>}|null
     */
    private static function descriptor(Request $request): ?array
    {
        $data = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        // Nothing captured: a static-cache hit (served before the controller
        // ran) or a 404. Find what lives at the URL, once per request.
        if ($data === null && ! $request->attributes->get(self::LOOKED_UP) && self::isFrontendRequest($request)) {
            $request->attributes->set(self::LOOKED_UP, true);
            $data = self::lookup($request);
            $request->attributes->set(self::REQUEST_ATTRIBUTE, $data);
        }

        // Structured collections (and nav-mounted URLs) resolve to a Page
        // wrapper around the entry, not the entry itself.
        if ($data instanceof Page) {
            $data = self::pageEntry($data);
        }

        if ($data instanceof Entry) {
            $collection = $data->collectionHandle();
            $blueprint = self::blueprintHandle($data);

            return [
                'label' => 'entry:'.$collection.($blueprint !== null ? '.'.$blueprint : ''),
                'attributes' => array_filter([
                    'statamic.type' => 'entry',
                    'statamic.entry.id' => (string) $data->id(),
                    'statamic.collection' => $collection,
                    'statamic.blueprint' => $blueprint,
                    'statamic.site' => $data->locale(),
                    'statamic.content.title' => self::guard(fn () => $data->value('title')),
                    'statamic.content.url' => self::guard(fn () => $data->url()),
                    'statamic.content.edit_url' => self::guard(fn () => $data->editUrl()),
                ], fn ($value) => $value !== null && $value !== ''),
            ];
        }

        if ($data instanceof LocalizedTerm) {
            $taxonomy = $data->taxonomy()->handle();

            return [
                'label' => 'term:'.$taxonomy,
                'attributes' => array_filter([
                    'statamic.type' => 'term',
                    'statamic.term.id' => (string) $data->id(),
                    'statamic.taxonomy' => $taxonomy,
                    'statamic.site' => $data->locale(),
                    'statamic.content.title' => self::guard(fn () => $data->title()),
                    'statamic.content.url' => self::guard(fn () => $data->url()),
                    'statamic.content.edit_url' => self::guard(fn () => $data->editUrl()),
                ], fn ($value) => $value !== null && $value !== ''),
            ];
        }

        // A taxonomy index/listing page (e.g. /topics) — distinct from a
        // single term page above. Not localized, so the site comes from
        // Content::attributes()'s Site::current() fallback.
        if ($data instanceof Taxonomy) {
            $handle = $data->handle();

            return [
                'label' => 'taxonomy:'.$handle,
                'attributes' => [
                    'statamic.type' => 'taxonomy',
                    'statamic.taxonomy' => $handle,
                ],
            ];
        }

        return null;
    }

    /** What Statamic would serve at this URL — an entry, term, taxonomy or page, or null. One Stache uri-index hit. */
    private static function lookup(Request $request): mixed
    {
        try {
            return Data::findByRequestUrl($request->url());
        } catch (Throwable) {
            return null;
        }
    }

    private static function guard(callable $read): ?string
    {
        try {
            $value = $read();
        } catch (Throwable) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private static function blueprintHandle(Entry $entry): ?string
    {
        try {
            return $entry->blueprint()?->handle();
        } catch (Throwable) {
            return null;
        }
    }

    private static function pageEntry(Page $page): mixed
    {
        try {
            return $page->entry();
        } catch (Throwable) {
            return null;
        }
    }
}
