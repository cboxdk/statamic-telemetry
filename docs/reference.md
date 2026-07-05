---
title: Reference
description: Every span attribute, metric and config toggle, and the events they derive from
---

# Reference

The canonical catalog. Metric names follow the base package's convention
(lowercase, dot-namespaced; `.` becomes `_` in Prometheus, so
`statamic.static_cache.operations` scrapes as
`statamic_static_cache_operations_total`).

## Span attributes

All on the **request root span** unless noted. Attributes are per-span, so
their cardinality is unconstrained — ids and slugs are fine here (unlike
metric labels).

| Attribute | Where | Example |
|---|---|---|
| `statamic.type` | root | `entry`, `term`, `taxonomy` |
| `statamic.entry.id` / `statamic.term.id` | root | the id |
| `statamic.collection` | root | `blog` |
| `statamic.blueprint` | root | `article` |
| `statamic.taxonomy` | root | `topics` |
| `statamic.site` | root + every span (ambient context) | `default` |
| `statamic.static_cache` | root | `hit`, `miss`, `write` |
| `enduser.roles` | root | `editor,author` (sorted, comma-joined) |
| `enduser.groups` | root | `staff` |
| `enduser.super` | root | `true` (present only when super) |
| `statamic.blink.hits` / `statamic.blink.misses` | root (tally) | `53` / `21` |
| `http.route` | root + request metric label (overridden) | `entry:blog.article`, `term:topics`, `taxonomy:topics` — the logical content route (see below) |
| `http.route.template` | root (when overridden) | `/{segments?}` — the raw Laravel template |
| `cache.key.group` | base cache spans | `stache.index`, `stache.item`, `stache.meta`, `static_cache`, `app` |
| `view.path` / `view.engine` | `view.render` spans (opt-in) | `resources/views/blog/show.antlers.html`, `antlers` |
| `antlers.tag` | `antlers:{tag}` spans (opt-in) | `collection`, `partial` (bounded) |
| `antlers.method` | `antlers:{tag}` spans (opt-in) | `blog`, `components/hero` (unbounded) |

`enduser.id`, `enduser.type` and `enduser.guard` come from the base
package; the addon adds the three role/group/super attributes on top.

### Request span naming

Every Statamic frontend request runs through one catch-all route, so the
base package's default `METHOD /route/{pattern}` name collapses to a
single value. The addon renames the root span from the resolved content:

| Content | Span name |
|---|---|
| Entry | `GET entry:{collection}.{blueprint}` |
| Term | `GET term:{taxonomy}` |
| Taxonomy index/listing | `GET taxonomy:{taxonomy}` |
| Anything else (or no content) | base package default |

These are exactly the data types Statamic serves as frontend pages
(everything that reaches `ResponseCreated` via a `DataResponse`: entries,
structured-collection pages, taxonomy terms, taxonomy index pages).
Assets, globals and navigations are **not** frontend-routable — they are
files and template data, injected into views rather than served as their
own pages — so they never get a span name. Their edits are still counted
by `statamic.content.changes`.

Names are **bounded** — collection/blueprint/taxonomy handles, never ids
or slugs. `http.route` keeps the raw catch-all pattern regardless, so
route-based filtering still works. A static cache **hit** never reaches
the controller, so hit traces keep the generic route-pattern name (with
`statamic.static_cache: hit`).

### `http.route` is the logical content route

Every frontend request matches the same catch-all template
(`/{segments?}`), so left alone the base package's `http.route` label
collapses every page into one series — route tables and latency
histograms show a single bucket. The addon uses the base package's
`resolveRouteUsing()` hook to **override `http.route`** with the logical
content route (`entry:{collection}.{blueprint}` / `term:{taxonomy}` /
`taxonomy:{taxonomy}`), so the whole ecosystem — the UI route table,
Grafana, TraceQL — groups by content with no per-consumer change:

```promql
histogram_quantile(0.95, sum by (le, http_route) (
  rate(http_server_request_duration_milliseconds_bucket{http_route=~"(entry|term|taxonomy):.*"}[5m])
))
```

The value is **bounded** (collections and taxonomies are a fixed set),
which is why the addon — not the base package — owns it: only the addon
knows these names are safe as a metric label. The raw Laravel template is
preserved as `http.route.template` for debugging. Non-content requests
(real routes, 404s) keep their normal `http.route`.

## Metrics

| Metric | Type | Labels | Source event |
|---|---|---|---|
| `statamic.static_cache.operations` | counter | `operation`: hit, miss, write, invalidate, flush | cacher subclass overrides |
| `statamic.stache.warms` | counter | — | `StacheWarmed` |
| `statamic.stache.clears` | counter | — | `StacheCleared` |
| `statamic.stache.warm_duration` | histogram (ms) | — | `StacheWarmed` + `Stache::buildTime()` |
| `statamic.glide.generations` | counter | `preset` (ad-hoc params → `custom`) | `GlideImageGenerated` |
| `statamic.glide.cache_clears` | counter | `scope`: all, asset | `GlideCacheCleared`, `GlideAssetCacheCleared` |
| `statamic.forms.submissions` | counter | `form` | `SubmissionCreated` |
| `statamic.content.changes` | counter | `type`, `action` | 40+ content events (see below) |
| `statamic.search.index_updates` | counter | `index` | `SearchIndexUpdated` |
| `statamic.auth.events` | counter | `event` (see below) | 10 auth/2FA events |
| `statamic.entries.count` | gauge (opt-in) | `collection` | scrape-time query |
| `statamic.assets.count` | gauge (opt-in) | `container` | scrape-time query |
| `statamic.users.count` | gauge (opt-in) | — | scrape-time query |

The base package's `cache.operations` counter additionally carries the
`key_group` label from the addon's classifier when
`telemetry.instrument.cache` is on.

## Annotation events

Unsampled telemetry events (OTLP log records) that the bundled Statamic
dashboard renders as annotation lines — so latency spikes map to their
cause at a glance, the same way they map to deploys.

| Event | Attributes | Source event |
|---|---|---|
| `statamic.cache.purge` | `cache.type`: stache, static, glide, glide_asset; `cache.trigger`: cli, http | `StacheCleared`, `StaticCacheCleared`, `GlideCacheCleared`, `GlideAssetCacheCleared` |

A purge explains the slow requests that follow it: a stache clear means
index rebuilds, a static cache clear means full renders, a glide clear
means image regeneration. `cache.trigger` separates deploy-pipeline
purges (`cli`) from CP or MCP-triggered ones (`http`).

### `statamic.content.changes` labels

`type` and `action` are derived from the event class name
(`AssetReplaced` → `asset`/`replaced`). Subscribed families: entries,
terms, localized terms, assets (saved, deleted, uploaded, replaced,
reuploaded, references-updated), asset folders and containers, collections
and their trees, taxonomies, globals (`GlobalVariablesSaved` — the actual
content edit — as well as the set), navs and their trees, forms, users,
roles, groups, blueprints, fieldsets, sites, deleted submissions. Two
carry explicit labels: `EntryScheduleReached` → `entry`/`schedule_reached`,
`DuplicateIdRegenerated` → `duplicate_id`/`regenerated`.

**Entry saves are special.** Instead of a flat `saved`, an `EntrySaved`
is labelled by the entry's **publish status** at save time — `published`,
`draft`, `scheduled` or `expired` — so `content.changes{type=entry}`
shows the publish-state mix of editing (how much live vs draft content is
being touched). It is a *status snapshot*, not a transition: Statamic has
already synced the pre-save published value away by the time the event
fires, so a true publish/unpublish can't be detected reliably at the event
boundary. `EntryDeleted` stays `entry`/`deleted`.

### Authentication metrics

The **Laravel auth lifecycle** — `login`, `logout`, `failed`, `lockout`,
`password_reset`, `registered`, `verified` — is emitted by the base
package as `auth.events{event, guard}` (with the guard dimension). The
`failed`/`lockout` spikes are the credential-attack signal. The addon's
`statamic.auth.events` (below) covers only the Statamic-specific events
the base package doesn't know about.

### `statamic.auth.events` labels

`impersonation_started`, `impersonation_ended`, `user_registered`,
`password_changed`, `two_factor_enabled`, `two_factor_disabled`,
`two_factor_challenged`, `two_factor_failed`, `two_factor_passed`,
`two_factor_recovery_code_replaced`. No user ids on the metric — identity
lives on the request trace via `enduser.*`.

## Config

`config/statamic-telemetry.php`. All `instrument.*` flags are also settable
via `STATAMIC_TELEMETRY_*` env vars (see the published config).

| Key | Default | Effect |
|---|---|---|
| `enabled` | true | Master switch for the whole overlay |
| `instrument.user` | true | `enduser.roles/groups/super` |
| `instrument.site_context` | true | `statamic.site` ambient dimension |
| `instrument.content` | true | Root span naming + entry/term attributes |
| `instrument.static_cache` | true | Cacher subclass swap, outcomes, header strip |
| `instrument.stache` | true | Cache-key classifier, warm/clear metrics |
| `instrument.glide` | true | Generation + cache-clear counters |
| `instrument.cache_purges` | true | `statamic.cache.purge` annotation event per cache clear |
| `instrument.forms` | true | Submission counter |
| `instrument.content_events` | true | Content-change counter |
| `instrument.search` | true | Search index-update counter |
| `instrument.auth` | true | Auth/security event counter |
| `instrument.blink` | true | Blink memoization tallies |
| `instrument.views` | false | A `view.render` detail span per rendered view |
| `instrument.antlers` | false | A detail span per Antlers tag (forces `statamic.antlers.tracing` on) |
| `gauges.enabled` | false | Inventory gauges (query on every scrape) |
| `cp.url` | auto | The "Telemetry" CP nav item's URL. Auto-links to the in-app `laravel-telemetry-ui` dashboard when installed; set `STATAMIC_TELEMETRY_UI_URL` to override (Grafana / remote UI). No UI + no url → no nav item |

## Antlers tags

For [browser tracing](browser-tracing.md) in Antlers templates:

| Tag | Output |
|---|---|
| `{{ telemetry:browser }}` | traceparent meta + the RUM `<script>` — empty when `telemetry.ingest.spans.enabled` is off |
| `{{ telemetry:traceparent }}` | just `<meta name="traceparent">` — empty when no trace is active |

Under static caching, the per-request traceparent and `data-session` are
stripped from cached copies by `BrowserTracingReplacer` (registered
automatically) so they never leak across visitors.

## What is deliberately not instrumented

- **Full-measure static cache hits** — served by the web server, never
  reach PHP, so they produce no telemetry at all. Only PHP-served hits,
  misses and writes appear.
- **Search queries** — Statamic fires no query event; latency shows up on
  the request span. Only index *updates* are counted.
- **Field augmentation** — no core events. Its cost is visible indirectly
  through the Blink tallies, the Antlers tag spans and the view spans.
- **Halting / lifecycle events** — `FormSubmitted` (a listener return
  cancels the submission), `*Saving`/`*Deleting`, `*BlueprintFound` are
  payload-manipulation hooks, not outcomes.
- **`UrlInvalidated`** — already counted at the cacher as `invalidate`.
