---
title: Design notes
description: Why the addon is built the way it is
---

# Design notes

The rationale behind the non-obvious decisions. If you are extending the
addon or debugging it, read this.

## The invariant: telemetry never throws into Statamic

The base package guarantees this for its own capture paths (everything
runs through `FailSafe::guard`). The addon must uphold it too, because it
hooks two kinds of Statamic surface:

1. **Resolver hooks** (`nameRequestsUsing`, `enrichRequestsUsing`,
   `resolveUserUsing`, `classifyCacheKeysUsing`) — the base package already
   wraps these in `FailSafe`, so a throw inside `Support\Content` or
   `Support\CacheKeys` is caught, reported and turned into "no
   name/attributes", never a broken request.
2. **Event listeners** — these fire *inside the dispatch of a real
   operation*: an entry save, an asset upload, a login. A throwing listener
   would break that operation. So every listener extends
   [`GuardedListener`](../src/Listeners/GuardedListener.php), whose `handle()`
   wraps the subclass `handleEvent()` in the same `FailSafe::guard`. A
   Redis outage, a malformed event payload, a missing method — none of it
   can break the save.

When adding a listener, extend `GuardedListener` and implement
`handleEvent(object $event)`. Don't add a `handle()` — the base provides
the guarded one.

## Content-named request spans

Every Statamic frontend URL matches one catch-all route, so the base
package names every frontend span identically. The fix is a two-part dance:

- A `ResponseCreated` listener ([CaptureResponseData](../src/Listeners/CaptureResponseData.php))
  stashes the content object Statamic resolved onto the request.
- The `nameRequestsUsing` and `enrichRequestsUsing` resolvers read it back
  at terminate — when the final response (and its status) is known — and
  derive a bounded name and attributes.

Structured collections (including the default skeleton's `pages`) resolve
to a `Structures\Page` wrapping the entry, not the entry itself, so
[`Content`](../src/Support/Content.php) unwraps `Page::entry()` first. This
was a real miss caught by the demo — unstructured-collection tests didn't
exercise it.

## Static cache: subclasses, not a decorator

Statamic's static-caching middleware and replacers make `instanceof
FileCacher` / `instanceof ApplicationCacher` checks to choose code paths.
A decorator around the `Cacher` contract would fail those checks and
silently change caching behaviour. So the addon re-registers the `file`
and `application` drivers as tracing *subclasses*
([TracingFileCacher](../src/StaticCaching/TracingFileCacher.php),
[TracingApplicationCacher](../src/StaticCaching/TracingApplicationCacher.php)).
Custom third-party cacher drivers are not instrumented.

The swap is registered via `afterResolving(StaticCacheManager::class)` in
the addon's `register()`, not `bootAddon()`. With a strategy configured at
boot — every real app — Statamic's own boot subscribes the cache
invalidator, which resolves and caches the driver before any `booted()`
callback runs. Extending from `bootAddon` would hand back the untraced
cacher. This was caught by the manual e2e, not the unit tests (which set
the strategy after boot).

### Recording is gated to the request being served

Statamic probes the cache with freshly built, synthetic `Request` objects
too — error-page copies (`copyError`), warm jobs. Those must not inflate
the hit/miss counters or overwrite the outcome attribute on the real
request's root span. So
[`StaticCacheTelemetry`](../src/StaticCaching/StaticCacheTelemetry.php)
records an outcome only when the request is the container's current request
(`isCurrentRequest`). Invalidations and flushes are not request-scoped and
are always counted.

### Stripping the trace id from cached responses

The base package exposes the trace id as an `X-Trace-Id` response header —
the support-case reference. Statamic's half-measure (`ApplicationCacher`)
snapshots response headers into the cache via its own `ResponsePrepared`
listener, so without intervention one visitor's trace id would be baked
into the cached page and replayed to everyone.

`TracingApplicationCacher::cachePage` flags the request; a boot-registered
`ResponsePrepared` listener ([StripTraceHeader](../src/Listeners/StripTraceHeader.php))
runs before the cacher's own header-snapshot listener and removes the
header. The flag lives on the request (not a class static) so that under
Octane a `cachePage` with no following `ResponsePrepared` can't leak the
strip into the next request. Full-measure (`FileCacher`) keeps the header
for the first, PHP-served visitor; dynamic responses always keep it.

## Stache

The Stache fires no per-operation events but runs on the Laravel cache
under the hood, so its traffic already flows through the base package's
cache instrumentation — as thousands of raw keys. The addon's
[`CacheKeys`](../src/Support/CacheKeys.php) classifier (registered via
`classifyCacheKeysUsing`) buckets them into bounded groups (`stache.index`,
`stache.item`, `stache.meta`, `static_cache`, `app`) so counters and spans
stay legible. Nothing is dropped — every operation keeps a group — so the
`key_group` label set stays consistent. Warm/clear counters and warm
duration come from `StacheWarmed`/`StacheCleared`.

## Blink

Blink is Statamic's per-request memoization layer — where augmentation
caching and repeated lookups live. It fires no events.
[`TracingBlink`](../src/Support/TracingBlink.php) is bound as a singleton
over `Statamic\Support\Blink` (the class the facade resolves), handing out
[`TallyingBlinkStore`](../src/Support/TallyingBlinkStore.php) instances that
count `once()` hits and misses onto the trace root span via `bumpStat` —
the request's memoization *effectiveness*, not per-key noise. It only
tallies inside an active trace, so Blink use in console commands and
untraced jobs doesn't accumulate unattached counts.

## Antlers

Two opt-in layers, both off by default because they add per-render cost:

- **`instrument.views`** wraps the Antlers view engine
  ([TracingEngine](../src/View/TracingEngine.php)) for one `view.render`
  span per rendered view.
- **`instrument.antlers`** registers a runtime tracer
  ([AntlersNodeTracer](../src/View/AntlersNodeTracer.php)) through
  Statamic's official `RuntimeTracerContract`, for a span per *tag*
  invocation. It forces `statamic.antlers.tracing` on, which is why it is
  opt-in — the runtime only consults tracers when tracing is enabled, and
  it costs per node.

Span names are the **bare tag name** (`antlers:partial`,
`antlers:collection`) — bounded to Statamic's registered tag set. The
method part (`partial:components/hero`, `nav:main`) is unbounded — one
value per partial file or dynamic target — so it goes on the
`antlers.method` attribute, never the span name (which Grafana groups on).
Both layers no-op when there is no active root span, so a render in a
console command or job doesn't mint one orphan trace per view or tag.

## Composing with your own resolvers

The base package's resolver hooks are single-slot — the last registration
wins. The addon registers during boot, so an app that registers its own
resolver afterwards *replaces* the Statamic one. To compose instead of
replace, delegate to the addon's public helpers:

```php
use Cbox\StatamicTelemetry\Hooks;
use Cbox\StatamicTelemetry\Support\CacheKeys;
use Cbox\StatamicTelemetry\Support\Content;

Telemetry::resolveUserUsing(fn ($user, $guard) => [
    ...Hooks::userAttributes($user, $guard),
    'enduser.plan' => $user->plan ?? null,
]);

Telemetry::classifyCacheKeysUsing(fn (string $store, string $key) =>
    str_starts_with($key, 'tenant:') ? 'tenant' : CacheKeys::classify($store, $key)
);
```
