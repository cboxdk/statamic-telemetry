# Statamic Telemetry

Statamic overlay for [cboxdk/laravel-telemetry](https://github.com/cboxdk/laravel-telemetry):
content-aware trace names, Stache and static-cache instrumentation, site
context and user attribution — as a proper Statamic addon.

laravel-telemetry already traces requests, queries, queue jobs, cache and
more. This addon teaches those traces Statamic's vocabulary:

- **Request span naming** — every Statamic frontend request goes through one
  catch-all route, so the default `METHOD /route/{pattern}` name collapses to
  a single useless value. Entry requests become `GET entry:{collection}.{blueprint}`,
  term requests `GET term:{taxonomy}` — bounded names, while `http.route`
  keeps the raw pattern.
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

Requires PHP 8.3+, Statamic 6 and cboxdk/laravel-telemetry ^2.4. Everything is
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

## UI

Three Control-Panel-native pages under *Tools*, all reading back from the
telemetry backend (telemetryd or an LGTM stack, over its Loki / Tempo query
APIs) — no React, no `laravel-telemetry-ui`, no build step:

### Native Telemetry dashboard

The **Telemetry** item is a native inline dashboard: request volume and rate,
p50 / p95 / max latency with sparkline and an avg-latency chart, the slowest
endpoints, and the most recent requests — each opening a single-trace
**waterfall**. Charts are server-rendered inline SVG. Built from the backend's
traces (metric and log sections follow). Gated by the **View the telemetry
dashboard** permission.

Setting `STATAMIC_TELEMETRY_UI_URL` (a Grafana dashboard or a remote
`laravel-telemetry-ui`) turns the item back into a link-out — an explicit
opt-out from the native page.

### Native Content audit page

A second item, **Content audit** under *Tools*, is a Control-Panel-native page
— no telemetry-ui, no React — that reads the `statamic.content.change` records
back from the telemetry backend and lists them in the CP itself: who changed
what, when, the transition (created / published / deleted / …), the fields an
edit touched and a link into the CP. Filter by type, action and site, search,
and pick the window.

The store is the telemetry backend you already send to — **telemetryd** (the
single-binary `cboxdk/telemetryd`) or a full **LGTM** stack. Both answer the
Loki HTTP query API, so the page runs one LogQL range query and filters the
window in PHP; nothing new to run, and a backend that is down never breaks the
page (it shows an error state).

Point it at that backend's logs URL and the item appears, for users with the
**View content audit log** permission:

```dotenv
STATAMIC_TELEMETRY_AUDIT_LOGS_URL=http://localhost:3100   # telemetryd / Loki
# optional, as your backend needs them:
# STATAMIC_TELEMETRY_AUDIT_LOGS_TOKEN=...                 # bearer token
# STATAMIC_TELEMETRY_AUDIT_LOGS_TENANT=...                # X-Scope-OrgID
```

A site already running `laravel-telemetry-ui` needs nothing new — the URL and
token fall back to its `TELEMETRY_UI_LOGS_URL` / `TELEMETRY_UI_TOKEN`. See the
[reference](docs/configuration/reference.md#content-audit-page) for every key.

### Telemetry doctor

A third item, **Telemetry doctor** under *Tools*, is a read-only health page
for the pipeline: is telemetry enabled, where does it ship (OTLP endpoint,
metric store, exporters), how deep is the **spool** (entries still waiting to
ship), and does the audit log's backend answer. It is always available — most
useful precisely when something is down — and mirrors the key signals of
`php artisan telemetry:doctor`. Gated by the **View telemetry doctor**
permission.

## Testing

```bash
composer install
composer check   # pint + phpstan + pest
```

The test suite uses Statamic's `AddonTestCase` and `Telemetry::fake()`. Note
for addon/package authors: after swapping in the fake, re-register the hooks
(`Hooks::register($fake)`) — the boot-time registrations landed on the
manager the fake replaces. See `tests/TestCase.php::fakeTelemetry()`.
