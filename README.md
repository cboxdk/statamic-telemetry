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
- **User attribution** — `enduser.roles`, `enduser.groups` and `enduser.super`
  for Statamic users (file or eloquent driven), on top of the core
  `enduser.id/type/guard`.
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

Requires PHP 8.3+, Statamic 5.46+ and cboxdk/laravel-telemetry. Everything is
on by default except Antlers view spans and the gauges. Publish the config to
change toggles:

```bash
php artisan vendor:publish --tag=statamic-telemetry-config
```

## What you get

### Spans & attributes

| Attribute | Where | Values |
|---|---|---|
| `statamic.type` | root span | `entry`, `term` |
| `statamic.entry.id` / `statamic.term.id` | root span | id |
| `statamic.collection` / `statamic.blueprint` / `statamic.taxonomy` | root span | handles |
| `statamic.site` | root span + all spans (context) | site handle |
| `statamic.static_cache` | root span | `hit`, `miss`, `write` |
| `enduser.roles` / `enduser.groups` | root span | sorted, comma-joined handles |
| `enduser.super` | root span | `true` (only when super) |
| `cache.key.group` | cache spans (core) | `stache.index`, `stache.item`, … |
| `view.path` / `view.engine` | `view.render` detail spans (opt-in) | relative path, `antlers` |

### Metrics

| Metric | Type | Labels |
|---|---|---|
| `statamic.static_cache.operations` | counter | `operation`: hit, miss, write, invalidate, flush |
| `statamic.stache.warms` / `statamic.stache.clears` | counter | — |
| `statamic.stache.warm_duration` | histogram (ms) | — |
| `statamic.glide.generations` | counter | `preset` (ad-hoc params → `custom`) |
| `statamic.forms.submissions` | counter | `form` |
| `statamic.content.changes` | counter | `type`, `action` |
| `statamic.entries.count` | gauge (opt-in) | `collection` |
| `statamic.assets.count` | gauge (opt-in) | `container` |
| `statamic.users.count` | gauge (opt-in) | — |

Core cache counters additionally carry the `key_group` label from the
classifier.

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
with `python3 resources/grafana/generate.py`.

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
    'enduser.plan' => $user->plan ?? null,
]);

Telemetry::nameRequestsUsing(fn ($request, $response) =>
    $request->is('api/*') ? 'API '.$request->method() : Content::spanName($request)
);

Telemetry::classifyCacheKeysUsing(fn (string $store, string $key) =>
    str_starts_with($key, 'tenant:') ? 'tenant' : CacheKeys::classify($store, $key)
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
- **Trace header stripping.** The core package already skips the trace id
  header on `Cache-Control: public` responses (CDN caches). Statamic's
  half-measure cacher decides cacheability by its own rules and snapshots
  headers regardless, so the addon removes the header just before the
  snapshot — first visitors keep their header on file-measure, dynamic
  responses always keep it.
- **Everything is guarded.** Resolvers run inside laravel-telemetry's
  FailSafe; listeners check their config toggles at event time, so toggles
  work at runtime and in tests.

## UI

None yet — the addon is structured as a regular Statamic addon
(`AddonServiceProvider`), so CP nav, widgets and routes can be added later
without restructuring.

## Testing

```bash
composer install
composer check   # pint + phpstan + pest
```

The test suite uses Statamic's `AddonTestCase` and `Telemetry::fake()`. Note
for addon/package authors: after swapping in the fake, re-register the hooks
(`Hooks::register($fake)`) — the boot-time registrations landed on the
manager the fake replaces. See `tests/TestCase.php::fakeTelemetry()`.
