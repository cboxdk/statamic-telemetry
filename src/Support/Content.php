<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Support;

use Illuminate\Http\Request;
use Statamic\Entries\Entry;
use Statamic\Facades\Site;
use Statamic\Structures\Page;
use Statamic\Taxonomies\LocalizedTerm;
use Statamic\Taxonomies\Taxonomy;
use Throwable;

/**
 * Derives the root span name and attributes from the content object
 * Statamic bound to the response.
 *
 * All frontend requests share one catch-all route, so the default
 * "METHOD /route/{pattern}" name collapses to a single value. The
 * ResponseCreated listener stashes the resolved data on the request;
 * the nameRequestsUsing/enrichRequestsUsing resolvers read it back at
 * terminate. Names stay bounded: collection and blueprint handles,
 * never ids or slugs.
 */
final class Content
{
    public const REQUEST_ATTRIBUTE = 'statamic_telemetry_data';

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
     * @return array<string, scalar|null>
     */
    public static function attributes(Request $request): array
    {
        $attributes = self::descriptor($request)['attributes'] ?? [];

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
                ], fn ($value) => $value !== null),
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
                ], fn ($value) => $value !== null),
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
