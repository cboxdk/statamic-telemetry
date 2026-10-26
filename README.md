# Statamic Telemetry

Statamic overlay for [cboxdk/laravel-telemetry](https://github.com/cboxdk/laravel-telemetry):
content-aware trace names, Stache and static-cache instrumentation, site
context and user attribution — as a proper Statamic addon.

laravel-telemetry already traces requests, queries, queue jobs, cache and
more. This addon teaches those traces Statamic's vocabulary:

- **Request span naming** — every Statamic frontend request goes through one
  catch-all route, so the default `METHOD /route/{pattern}` name collapses to
  a single useless value. Entry requests become `GET entry:{collection}.{blueprint}`,
  term requests `GET term:{taxonomy}` — bounded names, static-cache hits
  included — and `http.route` carries the same logical route (the raw
  pattern is kept as `http.route.template`).
- **Content attributes** — `statamic.entry.id`, `statamic.collection`,
  `statamic.blueprint`, `statamic.taxonomy`, `statamic.term.id` and
  `statamic.site` on the request root span.
- **Site context** — `statamic.site` as an ambient dimension on every span in
  the trace, propagated to queued jobs.
- **User attribution** — `user.roles`, `user.groups` and `user.super`
  for Statamic users (file or eloquent driven), on top of the core
  `user.id/type/guard`.
- **Static cache** — hit/miss/write outcome as `statamic.static_cache` on the
  root span, operation counters, and a fix for a subtle replay bug: the
  half-measure cacher snapshots response headers, so the addon strips the
  `X-Trace-Id` header before it is baked into the cached response and
  replayed to every visitor.
- **Stache** — cache keys classified into bounded groups (`stache.index`,
  `stache.item`, `stache.meta`, `static_cache`, `app`) so cache counters and
  spans don't drown in thousands of raw keys; warm/clear counters and warm
  duration.
- **Glide, forms, content changes** — counters per preset, per form, and per
  content type/action.
- **Antlers render spans** (opt-in) — a detail span per rendered view.
- **Observable gauges** (opt-in) — entries per collection, assets per
  container, user count, evaluated at scrape time.

## Installation

```bash
composer require cboxdk/statamic-telemetry
```

Requires PHP 8.3+, Statamic 6 and cboxdk/laravel-telemetry ^3.0. Everything is
on by default except Antlers view/tag spans and the gauges. Publish the
config to change toggles:

```bash
php artisan vendor:publish --tag=statamic-telemetry-config
```

## What you get

### Spans & attributes

| Attribute | Where | Values |
|---|---|---|
| `statamic.type` | root span | `entry`, `term`, `taxonomy` |
| `http.route` | root span + request metric | logical content route: `entry:blog.article`, `term:topics`, `taxonomy:topics` |
| `http.route.template` | root span (when overridden) | raw Laravel template: `/{segments?}` |
| `statamic.entry.id` / `statamic.term.id` | root span | id |
| `statamic.collection` / `statamic.blueprint` / `statamic.taxonomy` | root span | handles |
| `statamic.site` | root span + all spans (context) | site handle |
| `statamic.static_cache` | root span | `hit`, `miss`, `write` |
| `user.roles` / `user.groups` | root span | sorted, comma-joined handles |
| `user.super` | root span | `true` (only when super) |
| `cache.key.group` | cache spans (core) | `stache.index`, `stache.item`, … |
| `statamic.blink.hits` / `statamic.blink.misses` | root span (tallies) | per-request Blink memoization effectiveness |
| `view.path` / `view.engine` | `view.render` detail spans (opt-in) | relative path, `antlers` |
| `antlers.tag` / `antlers.method` | `antlers:{tag}` detail spans (opt-in) | bounded tag name (`collection`, `partial`) + unbounded method (`blog`, `components/hero`) |

### Metrics

| Metric | Type | Labels |
|---|---|---|
| `statamic.static_cache.operations` | counter | `operation`: hit, miss, write, invalidate, flush |
| `statamic.stache.warms` / `statamic.stache.clears` | counter | — |
| `statamic.stache.warm_duration` | histogram (s) | — |
| `statamic.glide.generations` | counter | `preset` (ad-hoc params → `custom`) |
| `statamic.forms.submissions` | counter | `form` |
| `statamic.content.changes` | counter | `type`, `action` |
| `statamic.search.index_updates` | counter | `index` |
| `statamic.auth.events` | counter | `event`: impersonation, 2FA, registrations, password changes |
| `statamic.glide.cache_clears` | counter | `scope`: all, asset |
| `statamic.entries.count` | gauge (opt-in) | `collection` |
| `statamic.assets.count` | gauge (opt-in) | `container` |
| `statamic.users.count` | gauge (opt-in) | — |

Core cache counters additionally carry the `key_group` label from the
classifier.

### Content audit log

Each change behind `statamic.content.changes` is also a
`statamic.content.change` event in the logs backend: the item (id, title,
collection, site, URL, CP edit link), what the save did (`created`,
`updated`, `published`, `unpublished`, `deleted`, `uploaded`, …), the fields
it changed and the editor who made it — entries, terms, assets, collections,
blueprints, fieldsets, navigations, globals, users, forms and revisions.
`laravel-telemetry-ui` lists them on **Statamic → Content**. See the
[reference](docs/configuration/reference.md#content-audit-log) for every
attribute.

## Grafana dashboard

The addon bundles a **Statamic** dashboard for the Grafana suite that
ships with laravel-telemetry (`telemetry:dashboards`): static cache hit
ratio and operations, Stache traffic by key group and warm duration,
content changes, form submissions, Glide generations, the opt-in
inventory gauges, and Tempo panels for content traces and slow uncached
pages.

```bash
php artisan statamic-telemetry:dashboards            # import into http://localhost:3000
php artisan statamic-telemetry:dashboards --grafana=https://grafana.example.com --token=...
php artisan statamic-telemetry:dashboards --export=./grafana-provisioning
```

It carries the same `telemetry` tag as the core suite, so it appears as
a tab alongside Overview, Requests, Jobs, etc. Panels are regenerated
with `php resources/grafana/generate.php`.

The addon adds a **Telemetry** item to the Control Panel nav pointing at
your telemetry UI — the in-app [`cboxdk/laravel-telemetry-ui`](https://github.com/cboxdk/laravel-telemetry-ui)
dashboard automatically when installed (it has Statamic pages), or set
`STATAMIC_TELEMETRY_UI_URL` for Grafana / a remote UI.

## Browser tracing in Antlers

laravel-telemetry's browser RUM ([`@cboxdk/telemetry-browser`](https://www.npmjs.com/package/@cboxdk/telemetry-browser))
in Antlers templates — drop it in your layout `<head>`:

```antlers
{{ telemetry:browser }}      {{# meta traceparent + the RUM script #}}
{{ telemetry:traceparent }}  {{# just the meta, if you load the script yourself #}}
```

Both are empty when the span ingest (`TELEMETRY_INGEST_SPANS`) or the trace
is off. **Static caching is handled**: the per-request traceparent and
`data-session` are stripped from cached pages by a Statamic replacer (like
the CSRF one), so a cache hit never replays one visitor's server trace or
analytics session to everyone — the RUM self-roots instead. Full-measure
(file) hits are served without PHP, so they get a self-rooted browser trace
with no server span; the strip happens once when the file is compiled. See
[docs/browser-tracing](docs/production/browser-tracing.md).

## Composing with your own hooks

laravel-telemetry's resolver hooks are single-slot — the last registration
wins. This addon registers during boot, so if your app registers its own
resolver it replaces the Statamic one. Delegate to the addon's public
methods to compose:

```php
use Cbox\StatamicTelemetry\Hooks;
use Cbox\StatamicTelemetry\Support\CacheKeys;
use Cbox\StatamicTelemetry\Support\Content;

Telemetry::resolveUserUsing(fn ($user, $guard) => [
    ...Hooks::userAttributes($user, $guard),
    'user.plan' => $user->plan ?? null,
]);

Telemetry::nameRequestsUsing(fn ($request, $response) =>
    $request->is('api/*') ? 'API '.$request->method() : Content::spanName($request)
);

Telemetry::classifyCacheKeysUsing(fn (string $store, string $key) =>
    str_starts_with($key, 'tenant:') ? 'tenant' : CacheKeys::classify($store, $key)
);

// The addon claims resolveRouteUsing (it overrides http.route with the
// logical content route). Compose if you want a different route identity:
Telemetry::resolveRouteUsing(fn ($request, $response) =>
    $request->is('api/*') ? 'api' : Content::routeLabel($request)
);
```

## Design notes

- **Static cache drivers are subclassed, not decorated.** Statamic's
  middleware and replacers use `instanceof FileCacher/ApplicationCacher`
  checks to pick code paths; a decorator would silently change static
  caching behaviour. The addon re-registers the `file` and `application`
  drivers as tracing subclasses. Custom cacher drivers are not instrumented.
- **Full-measure hits are invisible.** With the `full` strategy, cached hits
  are served by the web server and never reach PHP — those requests produce
  no telemetry at all. Only PHP-served hits, misses and writes are recorded.
- **Cache hits keep the generic span name.** A static cache hit never
  reaches the frontend controller, so there is no entry to name the span
  by — hit traces are `GET /{pattern}` with `statamic.static_cache: hit`
  (and are fast). Content-named spans are the renders.
- **`http.route` is the content route.** Left alone, every frontend page
  shares the `/{segments?}` catch-all, so route tables and latency
  histograms collapse into one bucket. The addon uses the base package's
  `resolveRouteUsing()` hook to override `http.route` with the bounded
  logical route (`entry:blog.article`, `taxonomy:topics`), so the UI route
  table, Grafana and TraceQL all group by content — no per-tool change.
  The raw template is kept as `http.route.template`. Frontend 404s (broken
  links, bots) bucket as `not_found` instead of `/{segments?}`; cache hits
  stay `/{segments?}` (served before any content resolves — and fast). See
  [docs/design-notes](docs/core-concepts/design-notes.md#httproute-is-the-content-route-not-the-catch-all).
- **Trace header stripping.** The core package already skips the trace id
  header on `Cache-Control: public` responses (CDN caches). Statamic's
  half-measure cacher decides cacheability by its own rules and snapshots
  headers regardless, so the addon removes the header just before the
  snapshot — first visitors keep their header on file-measure, dynamic
  responses always keep it.
- **Augmentation is observed indirectly.** Statamic fires no events around
  field augmentation, so there is no direct per-field instrumentation.
  Its cost shows up in three places instead: the Blink tallies (augmentation
  memoization lives in Blink), the opt-in `antlers:{tag}` spans (tags are
  where augmentation work runs), and the opt-in `view.render` spans.
  Per-field visibility would need an upstream hook in statamic/cms.
- **Search queries are not counted.** Statamic fires `SearchIndexUpdated`
  for index writes (counted) but no event for queries — query latency is
  visible on the request span.
- **Telemetry never throws into the app.** The resolver hooks run inside
  laravel-telemetry's FailSafe; every event listener extends a
  `GuardedListener` base that wraps its body in the same guard. A broken
  listener can never break the entry save, upload or login it observes.
  Listeners also check their config toggle at event time, so toggles work
  at runtime and in tests.

More detail — the full metric/attribute/config catalog and the rationale
behind each design decision — lives in [docs/](docs/index.md).

## Control Panel

Native telemetry pages inside the Statamic Control Panel — Vue/Inertia on
`@statamic/cms/ui`, assets compiled and committed (no Node on install) —
reading back from the backend you already send to (**telemetryd** or an
**LGTM** stack, over the Loki / Tempo / Prometheus query APIs). One rule
throughout: **a number is never the end of the road.** Every tile, chart and
breakdown sits above the rows it counts, every row opens its trace in a
slide-in drawer, and breakdown rows and chips filter the rows below them.

- **Overview** — requests by status class and latency with period deltas,
  exceptions, deploy markers, routes needing attention, recent requests.
- **Explore** — requests / traces / logs / errors with facets (status, method,
  route, collection, content type, static cache, site), group-by, search,
  a latency × time heatmap and live tail.
- **Activity pages** — *Requests, Jobs, Queries, Exceptions, Outgoing HTTP*:
  each an aggregate over its rows — every job run with its queue and
  attempt, every query with its SQL and repeat count, every exception with
  message and `file:line`, every outgoing call with host and status.
- **Statamic pages** — *Static cache, Stache, Glide, Forms, Content*: hit
  ratio and outcomes per page, warms and clears, generations per preset,
  submissions per form, and the **content audit trail** (who changed what,
  click to edit) under the change counts.
- **The drawer** — Statamic's own `Stack`: waterfall with category-coloured
  spans, span attributes in a nested stack with a menu per value (copy,
  filter Explore, open), and the next steps from the request: requests for
  its route, Explore its path, edit the entry, view the page. Deep-linkable
  (`?drawer=trace:<id>`), `Esc` pops, `j`/`k` step.
- **An entry's own Telemetry tab** — every entry blueprint gets a full-width
  tab with that page's traffic, latency against the site's p95, static-cache
  treatment, exceptions on its path, change history and recent requests
  (each opening its trace, in the editor). Off with
  `STATAMIC_TELEMETRY_ENTRY_TAB=false`.
- **Time** — period presets, a custom range, auto-refresh, and the time zone
  every timestamp is shown in (your browser's or UTC). Drag a chart to zoom.
- **Telemetry doctor** — the pipeline's health: switches, endpoint,
  exporters, spool depth, backend probe.

Requests are named by the content they served — `GET entry:pages.page`,
`GET not_found`, `GET glide:assets` — static-cache hits included, so a cached
and an uncached page are the same route in every table, and rows read as
*GET /about · About · entry:pages · cache hit*.

Point the pages at the backend's query URL (an existing `laravel-telemetry-ui`
setup needs nothing new — the URL and token fall back to its variables):

```dotenv
STATAMIC_TELEMETRY_AUDIT_LOGS_URL=http://localhost:4319   # telemetryd / LGTM gateway
# STATAMIC_TELEMETRY_AUDIT_LOGS_TOKEN=...                 # bearer token
# STATAMIC_TELEMETRY_AUDIT_LOGS_TENANT=...                # X-Scope-OrgID
```

The pages' own requests are kept out of the app's telemetry
(`STATAMIC_TELEMETRY_CP_IGNORE_OWN_ROUTES`), and
`STATAMIC_TELEMETRY_UI_URL` turns the Telemetry item back into a link-out.
Permissions: **View the telemetry dashboard**, **View content audit log**,
**View telemetry doctor**. The full guide, including what the backend must
answer, is in [docs/production/control-panel.md](docs/production/control-panel.md).

## Testing

```bash
composer install
composer check   # pint + phpstan + pest
composer qa      # check + dependency license gate + composer audit
composer sbom    # regenerate sbom.json (CycloneDX 1.5) after a dependency change
```

`sbom.json` lists every runtime Composer dependency and the npm packages
bundled into the compiled Control Panel assets (ECharts and what it pulls
in); it is generated deterministically from the committed lock files, and CI
fails when it is stale. `composer license-check` requires a permissive
license on every dependency, Composer and bundled npm alike; the two
exceptions (`statamic/cms` itself and its `getid3`) are recorded with their
justification in `bin/check-licenses.php`.

The test suite uses Statamic's `AddonTestCase` and `Telemetry::fake()`. Note
for addon/package authors: after swapping in the fake, re-register the hooks
(`Hooks::register($fake)`) — the boot-time registrations landed on the
manager the fake replaces. See `tests/TestCase.php::fakeTelemetry()`.
