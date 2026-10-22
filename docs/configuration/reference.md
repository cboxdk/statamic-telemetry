---
title: Reference
description: Every span attribute, metric and config toggle, and the events they derive from
weight: 21
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
| `user.roles` | root | `editor,author` (sorted, comma-joined) |
| `user.groups` | root | `staff` |
| `user.super` | root | `true` (present only when super) |
| `statamic.blink.hits` / `statamic.blink.misses` | root (tally) | `53` / `21` |
| `http.route` | root + request metric label (overridden) | `entry:blog.article`, `term:topics`, `taxonomy:topics` — the logical content route (see below) |
| `http.route.template` | root (when overridden) | `/{segments?}` — the raw Laravel template |
| `cache.key.group` | base cache spans | `stache.index`, `stache.item`, `stache.meta`, `static_cache`, `app` |
| `view.path` / `view.engine` | `view.render` spans (opt-in) | `resources/views/blog/show.antlers.html`, `antlers` |
| `antlers.tag` | `antlers:{tag}` spans (opt-in) | `collection`, `partial` (bounded) |
| `antlers.method` | `antlers:{tag}` spans (opt-in) | `blog`, `components/hero` (unbounded) |

`user.id`, `user.type` and `user.guard` come from the base
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
preserved as `http.route.template` for debugging.

**Frontend 404s become `not_found`.** A URL that matches the catch-all but
resolves to no entry/term/taxonomy (broken links, bots, stale sitemaps)
is bucketed as `http.route = not_found` instead of `/{segments?}`, so 404
traffic can be seen and sized separately rather than diluting the catch-all.
The `not_found` fallback is scoped to Statamic's `FrontendController` — a
404 on a real Laravel route keeps its own `http.route`.

**Cache hits keep `/{segments?}`.** A statically-cached page is served
before the controller resolves any content, so there is nothing to name it
by; it stays on the raw template and carries `statamic.static_cache: hit`.
These are the fast requests, so the catch-all bucket is effectively
"cache hits" — the slow, content-resolving requests are already named.

## Metrics

| Metric | Type | Labels | Source event |
|---|---|---|---|
| `statamic.static_cache.operations` | counter | `operation`: hit, miss, write, invalidate, flush | cacher subclass overrides |
| `statamic.stache.warms` | counter | — | `StacheWarmed` |
| `statamic.stache.clears` | counter | — | `StacheCleared` |
| `statamic.stache.warm_duration` | histogram (s, own buckets 0.05–300 s) | — | `StacheWarmed` + `Stache::buildTime()` |
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

## Content audit log

Every content change is also one `statamic.content.change` event (an OTLP
log record, correlated to the request or command that made it) — the row
behind the bounded `statamic.content.changes` counter. `laravel-telemetry-ui`
lists them on **Statamic → Content** as an audit log.

| Attribute | Values |
|---|---|
| `statamic.content.type` | `entry`, `term`, `asset`, `collection`, `taxonomy`, `blueprint`, `fieldset`, `nav`, `nav_tree`, `collection_tree`, `global_set`, `global_variables`, `form`, `user`, `role`, `user_group`, `asset_container`, `asset_folder`, `site`, `revision`, `submission`, … |
| `statamic.content.action` | `created`, `updated`, `saved` (nothing measurably changed), `published`, `unpublished`, `deleted`, `uploaded`, `replaced`, `reuploaded`, `schedule_reached`; revisions: `working`, `revision`, `publish`, `unpublish`, `restore` |
| `statamic.content.id` / `.title` / `.handle` / `.slug` | what an editor recognises the item by |
| `statamic.collection` / `statamic.taxonomy` / `statamic.asset.container` / `statamic.form` / `statamic.blueprint` | the item's home |
| `statamic.site` | the localisation (for a site record: the site itself; `statamic.site.locale` beside it) |
| `statamic.content.status` | an entry's status after the save |
| `statamic.content.url` / `statamic.content.path` / `statamic.content.edit_url` | the public URI, an asset's path, the absolute CP edit link |
| `statamic.content.changed` | the top-level field handles an update changed — names, never values |
| `statamic.revision.message` / `statamic.revision.key` | a revision's message and key |
| `statamic.content.origin` | `cp`, `api`, `web`, `console` (+ `process.command`) |
| `user.id` / `user.name` | the signed-in editor; the name can be switched off, the email is never sent |

Statamic fires several events for one save — `EntryCreated` then
`EntrySaved`, and re-saves an entry's localisations after it — so the record
keeps to what an editor did: the creation is one `created` row, and a save
Statamic made as a side effect of another (`EntrySaved::isInitial()`) is no
row at all. Publish transitions and changed fields come from Statamic's dirty
state; with a Stache cache store that hands back its cached instance (an
unserialised `array` store), that state is already synced by the time the
event fires, and the row says `saved` rather than guessing.

Sites are saved all at once: Statamic writes the whole sites config and
fires `SiteSaved` for every site, with no dirty state. When the CP's sites
form is submitted the addon takes a snapshot first, so only the sites that
changed become rows — `created`, `updated` (with the changed keys: `name`,
`url`, `locale`, `lang`, `attributes`, …) or `deleted` — with the site's
handle as `statamic.site`, its `statamic.site.locale` and URL. A sites save
made from code has nothing to compare against and is one `saved` row for
the batch.

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
lives on the request trace via `user.*`.

## Config

`config/statamic-telemetry.php`. All `instrument.*` flags are also settable
via `STATAMIC_TELEMETRY_*` env vars (see the published config).

| Key | Default | Effect |
|---|---|---|
| `enabled` | true | Master switch for the whole overlay |
| `instrument.user` | true | `user.roles/groups/super` |
| `instrument.site_context` | true | `statamic.site` ambient dimension |
| `instrument.content` | true | Root span naming + entry/term attributes |
| `instrument.static_cache` | true | Cacher subclass swap, outcomes, header strip |
| `instrument.stache` | true | Cache-key classifier, warm/clear metrics |
| `instrument.glide` | true | Generation + cache-clear counters |
| `instrument.cache_purges` | true | `statamic.cache.purge` annotation event per cache clear |
| `instrument.forms` | true | Submission counter |
| `instrument.content_events` | true | Content-change counter |
| `instrument.content_audit` | true | One `statamic.content.change` audit record per change (needs `content_events`) |
| `instrument.search` | true | Search index-update counter |
| `instrument.auth` | true | Auth/security event counter |
| `instrument.blink` | true | Blink memoization tallies |
| `instrument.views` | false | A `view.render` detail span per rendered view |
| `instrument.antlers` | false | A detail span per Antlers tag (forces `statamic.antlers.tracing` on) |
| `gauges.enabled` | false | Inventory gauges (query on every scrape) |
| `audit.actor_name` | true | The editor's `user.name` beside `user.id` on audit records |
| `audit.changed_fields` | true | The changed field handles (names only) on `updated` records |
| `cp.url` | — | Turns the "Telemetry" nav item back into a link-out (Grafana / a remote `laravel-telemetry-ui`), an opt-out from the native dashboard. Unset → the native Telemetry page |
| `cp.audit.url` | — | Logs backend URL for the native Content audit page (telemetryd / Loki). Falls back to `TELEMETRY_UI_LOGS_URL`. No URL → no page |
| `cp.audit.token` | — | Bearer token for the logs backend. Falls back to `TELEMETRY_UI_TOKEN` |
| `cp.audit.tenant` | — | `X-Scope-OrgID` tenant for a multi-tenant backend. Falls back to `TELEMETRY_UI_LOGS_TENANT` |
| `cp.audit.service` | app's | `service.name` to match; defaults to `telemetry.service.name` |
| `cp.audit.service_label` | `service_name` | The Loki stream label the service landed on |
| `cp.audit.selector` | — | A full LogQL stream selector, overriding the one built from `service`/`service_label` |
| `cp.audit.window` | `24h` | Default look-back window (`1h`, `24h`, `7d`, `30d`) |
| `cp.audit.limit` | 1000 | Newest-N records pulled from the backend before filtering in PHP |
| `cp.audit.timeout` | 5 | Request timeout to the logs backend, in seconds |
| `cp.audit.enabled` | auto | Force the page off; otherwise on when a URL resolves |

### Telemetry dashboard page

A native **Telemetry** page (nav: *Tools → Telemetry*, with **Content audit**
and **Telemetry doctor** as sub-items) replaces the link-out to
`laravel-telemetry-ui`. It reads the backend (telemetryd or an LGTM stack, over
its Tempo and Prometheus APIs on `cp.audit.url`):

- **Traces** — request volume/rate, p50/p95/max latency with inline-SVG charts,
  the slowest endpoints and recent requests; each opens a single-trace
  waterfall.
- **Metrics** — requests grouped by the addon's bounded content route
  (`entry:{collection}.{blueprint}`, not the catch-all `/{segments?}`), the
  static-cache hit ratio and operations, and content changes by type/action.

Guarded throughout (empty state when the backend has nothing / is unreachable)
and gated by **View the telemetry dashboard**. Set `cp.url` to opt back out to
Grafana / a remote UI.

The metric section needs metrics in the backend: run the app with a shared
store (`TELEMETRY_STORE=sqlite` / `redis` / `apcu`, not the default per-process
`array`) and `php artisan telemetry:flush` (or `--daemon`) shipping them over
OTLP. Traces and the audit log need no such wiring.

### Content audit page

A Control-Panel-native **Content audit** page (nav: *Tools → Content audit*)
reads the `statamic.content.change` records back from the telemetry backend —
**telemetryd** or an **LGTM** stack, both over the Loki query API — and lists
them in the CP itself, with no dependency on `laravel-telemetry-ui`. One LogQL
range query over the window; facets, search and pagination run in PHP. A
backend that is missing, slow or down never throws into the CP — the page
renders an error state. Shown only to users with the **View content audit log**
permission, and only when `cp.audit.url` resolves.

### Telemetry doctor page

A read-only **Telemetry doctor** page (nav: *Tools → Telemetry doctor*) shows
the pipeline's health: `telemetry.enabled`, the metric store driver, the OTLP
endpoint and exporters, the OTLP **spool** depth (`Spool::size()` — entries
waiting to ship), and a live probe of the audit log's backend (reachable /
HTTP status). It never throws, and is always in the nav — a diagnostics page
is most useful when something is down. Gated by the **View telemetry
diagnostics** permission. For the full end-to-end check run
`php artisan telemetry:doctor`.

## Antlers tags

For [browser tracing](../production/browser-tracing.md) in Antlers templates:

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
