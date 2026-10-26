# Changelog

All notable changes to `cboxdk/statamic-telemetry` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [3.2.0] - 2026-10-26

### Added

- **A native telemetry UI in the Control Panel.** Vue/Inertia pages on
  `@statamic/cms/ui` (assets compiled and committed — no Node on install)
  that read back from the telemetry backend you already send to —
  telemetryd or an LGTM stack, over its Loki / Tempo / Prometheus query
  APIs. One rule throughout: a number is never the end of the road. Every
  tile, chart and breakdown sits above the rows it counts, and every row
  opens its trace. The **Telemetry** item now opens these pages; set
  `STATAMIC_TELEMETRY_UI_URL` to keep the old link-out.
  - **Overview** — requests by status class and latency with
    period-over-period deltas, exceptions, deploy markers (from
    `telemetry:deploy`), routes needing attention, recent requests.
  - **Explore** — requests / traces / logs / errors with facets (status,
    method, route, collection, taxonomy, content type, static cache, site),
    group-by, search, a latency × time heatmap, brush-to-zoom and live tail.
  - **Requests, Jobs, Queries, Exceptions, Outgoing HTTP** — each an
    aggregate over its rows: every job run with queue and attempt, every
    `db.query` with its SQL and repeat count, every exception with message
    and `file:line`, every outgoing call with host and status.
  - **Static cache, Stache, Glide, Forms, Content** — hit ratio and
    outcomes per collection, warms and clears, generations per preset,
    submissions per form, changes by action and type with the **content
    audit trail** as the rows (`telemetry/audit` redirects there).
  - **The trace drawer** — Statamic's own `Stack`, URL-bound
    (`?drawer=trace:<id>`, nests, `Esc` pops, `j`/`k` step): waterfall with
    category-coloured spans and a ruler, span attributes in a nested stack
    with a menu per value (copy, filter Explore, open), the trace's log
    lines when the backend linked any, and the next steps from the request
    — requests for its route, Explore its path, edit the entry, view the
    page.
  - **An entry's own Telemetry tab.** Every entry blueprint gets a
    full-width tab (`EntryBlueprintFound`, for users who may view
    telemetry; the field is `computed`, nothing is saved) with that page's
    traffic, latency against the site's p95, static-cache treatment,
    exceptions on its path, change history and recent requests, each
    opening its trace in the editor. Served by `telemetry/entry/{id}`.
    `STATAMIC_TELEMETRY_ENTRY_TAB=false` turns it off.
  - **Time** — period presets, a custom range (`from`/`to`), auto-refresh,
    and a time-zone switch (browser or UTC) that every timestamp honours.
  - **Telemetry doctor** — the pipeline's health, now native too.
  - Permissions: **View the telemetry dashboard**, **View content audit
    log**, **View telemetry doctor**.
- **Static-cache hits are named by their content.** A hit answers before the
  controller resolves anything, so nothing was captured to name it by; the
  addon now looks the URL up (one Stache uri-index hit, once per request)
  and a cached page is `GET entry:pages.page` like an uncached one, with
  `statamic.static_cache: hit` to tell them apart. Request spans also carry
  `statamic.content.title`, `statamic.content.url` and
  `statamic.content.edit_url`. Glide requests become `glide:{container}`
  with `statamic.glide.preset`.
- **Supply-chain gate.** `composer license-check` (a permissive license on
  every dependency — Composer and the npm packages bundled into the CP
  assets; exceptions are recorded with a justification), `composer sbom`
  (a deterministic CycloneDX 1.5 `sbom.json` from the committed lock files,
  checked for drift in CI) and `composer qa` running the whole gate.
  `composer.lock` and `package-lock.json` are now committed for that.
- **Every Control Panel string is translatable.** Vue templates and the
  PHP that feeds them pass through `__()` / `__n()` with the English as the
  key, so an app's `lang/<locale>.json` translates the pages, the drawer and
  the entry tab like core.
- **A Vitest suite** for the CP JavaScript (formatting, URL state and the
  drawer stack, the CP url helper, RequestRow and Tile), run in CI beside
  Pest; phpstan runs at level 8.
- `Traces::spans()` (the spans a TraceQL query matched, with their trace),
  `Logs::withLabel()` / `Logs::forTrace()`, `TelemetryMetrics::increase()`
  / `countsBy()` / `increaseSeries()` / `p95Ms()`, `TimeRange` (window or
  `from`/`to`, now up to `30d`) and `TelemetryPages` — the readers the
  pages are built from.

### Security

- The entry telemetry endpoint (`cp/telemetry/entry/{id}`) authorises
  against the entry's own `view` policy, and the change history in the
  entry's story is returned only to users with `view content audit log`
  — the same two gates the entry editor and the Content page already apply.
- ECharts 6.1 (GHSA-fgmj-fm8m-jvvx, an XSS in the chart tooltip) and
  Vitest 5. `npm audit` is part of the CI quality gate, next to
  `composer audit`.
- Every GitHub Action in the workflows is pinned to a commit SHA, and the
  CI npm install runs with `--ignore-scripts`.

### Changed

- The addon's own Control Panel requests (`cp/telemetry*`) and their calls
  to the backend are kept out of the app's traces and metrics
  (`Telemetry::ignorePaths`); they were the busiest route and host in the
  very pages that display them. `STATAMIC_TELEMETRY_CP_IGNORE_OWN_ROUTES=false`
  restores the old behaviour.
- The `telemetry` fieldtype renders the full entry story (fetched from the
  new endpoint) instead of a two-tile snapshot; its `preload()` now carries
  the entry and the endpoint, not the data.
- The addon ships its own Tailwind build (`resources/css/cp.css`,
  `@tailwindcss/vite`) so every utility its templates use exists in the CP;
  the earlier hand-written `.tel-*` stylesheet is gone.
- `MetricsOverview` no longer carries the Statamic signals (static cache,
  Stache, Glide, forms, content changes) — each has its own page, and the
  dashboard no longer runs their eight PromQL queries on every load.

### Fixed

- Trace rows in the lists carried no attributes: the search API returns the
  *matched* span's attributes, and without a selector that was the first
  span by start time (`laravel.bootstrap`). Lists now match the server span
  explicitly.
- The CP's `utilities` layer out-ranks the addon's `addon-utilities`, so a
  core base class paired with an addon-only variant lost (a light selected
  row in dark mode, a stacked facet rail). Templates no longer pair them.
- A p95 series the backend could not compute (telemetryd caps the histogram
  scan beyond a few hours) is left out rather than drawn as a flat zero.

## [3.1.0] - 2026-10-21

### Added

- **A content audit log.** Every content change is now also one
  `statamic.content.change` event in the logs backend — the item (id,
  title, handle, collection/taxonomy/container, site, URL, CP edit link),
  what the save did (`created`, `updated`, `published`, `unpublished`,
  `deleted`, `uploaded`, `replaced`, revision `publish`/`working`/…), the
  field handles an update changed (never their values) and who made it
  (`user.id`, and `user.name` unless `audit.actor_name` is off; never the
  email), from where (`cp`, `api`, `web`, `console` + command). Covers
  entries, terms, assets, collections, taxonomies, blueprints, fieldsets,
  navigations and their trees, global sets and variables, users, roles,
  groups, forms, sites and revisions. `*Created` events are now listened
  to so a creation is one `created` row, and an entry saved only as a side
  effect of another (its localisations) is no row. Switch it off with
  `STATAMIC_TELEMETRY_CONTENT_AUDIT=false`; `laravel-telemetry-ui` 3.1 lists
  the records on Statamic → Content.
- **Sites in the audit log, one row per site that changed.** Statamic saves
  every site at once and fires `SiteSaved` for each; the addon snapshots
  the sites when the CP's sites form is submitted and records only the
  sites that were created, changed (naming the keys: `name`, `url`,
  `locale`, …) or removed. A sites save from code is one `saved` row.

### Changed

- **The `statamic.content.change` span carries the audit record's detail.**
  Its `statamic.content.action` is now what the save did (`created`,
  `updated`, …) rather than the counter's label; the counter itself is
  unchanged, so dashboards and alerts on `statamic.content.changes` keep
  working.
- **`statamic.stache.warm_duration` has its own buckets** (0.05 s – 300 s)
  instead of `telemetry.default_buckets`. A host config published before
  3.0 still carries millisecond default buckets (1 … 10000), which put every
  warm in the first bucket or two and made its percentiles meaningless. The
  first window after deploying this reads the old bucket counts against the
  new bounds; it settles on its own.

### Fixed

- **The "Telemetry" CP nav item was gone for everyone on
  `laravel-telemetry-ui` 2.x and later.** It looked for the 1.x route name
  (`telemetry-ui.page`); 2.x serves the dashboard from `telemetry-ui.spa`.
  Both are recognised now, and a test builds the CP nav with the real
  `laravel-telemetry-ui` installed (CI adds it on PHP 8.4+), so a renamed
  route fails the suite instead of the nav.

## [3.0.0] - 2026-09-27

A major because the metric changed unit. `cboxdk/laravel-telemetry` 3.0 put
every duration in seconds, per the semantic conventions, and this package
follows — an install still emitting 2.x telemetry will not resolve this
version.

### Breaking

- **Requires `cboxdk/laravel-telemetry` ^3.0.**
- **`statamic.stache.warm` is recorded in SECONDS, not milliseconds.** The
  value is divided at the record call, so the number moves with the unit
  rather than being relabelled. Any dashboard, alert or recording rule
  reading that metric needs its threshold divided by a thousand; nothing
  warns at runtime, because a duration that is a thousand times too small
  is still a valid duration.

## [2.0.1] - 2026-09-23

### Fixed

- **A static-cache outcome is no longer counted twice in one request.** The
  guard promised "count each outcome once per request" but kept a single
  last-value slot, so it suppressed immediate repeats only. The sequence it
  was written for — a middleware probe misses, the rendered page is cached,
  and something probes again inside the same serve (nocache, an error copy)
  — recorded `miss` twice on `statamic.static_cache.operations`. One page
  serve reported as two misses is a hit ratio that lies. Every outcome
  already counted is now remembered, not just the last one; the repeat probe
  also no longer reopens the span's `statamic.static_cache` attribute as a
  miss after the write.

## [2.0.0] - 2026-09-22

Tracks the `cboxdk/laravel-telemetry` 2.x line. Major because the user
attribution attributes are renamed — see the upgrade note below.

### Changed

- **Require `cboxdk/laravel-telemetry ^2.4`** (was `^1.0`). Verified against
  telemetry 2.4.0: the suite passes unchanged (77 tests), pint and PHPStan
  are clean.

- **User attribution moved to the `user.*` namespace**: `user.roles`,
  `user.groups` and `user.super` (were `enduser.*`). Telemetry 2.0 dropped
  `enduser.*` — deprecated in semconv 1.27 — for `user.id`/`user.type`/
  `user.guard`, so the overlay's three attributes were the only `enduser.*`
  keys left on the span. One vocabulary, not two.

  **Upgrade note.** TraceQL queries, dashboards and collector processors
  keying on `enduser.roles`, `enduser.groups` or `enduser.super` need the
  new names. An app that composes its own resolver on top of
  `Hooks::userAttributes()` should move its own keys with them.

### Fixed

- The README install block asked for `cboxdk/statamic-telemetry:^0.2.0` and
  described the package as 0.x SemVer — two majors stale since 1.0.0.

## [1.1.0] - 2026-07-15

### Added

- **Per-operation child spans for drill-down.** Glide generations, form
  submissions, content changes and Stache warms now emit a span on the active
  request trace (`Telemetry::tracer()->recordSpan()`), carrying the identifying
  attributes — Glide preset + source path, form handle, content type/action. Each
  individual operation is now drillable and correlated to the request (or command)
  that triggered it, instead of only an aggregate counter. The counters stay for
  cheap rate/overview charts.
  - Stache warms carry their **real build duration**; the others are point-in-time
    markers (the events fire *after* the work, with no start time — a true
    end-to-end duration would need inline instrumentation of the operation).
  - Static-cache hit/miss/write outcomes are intentionally left as an attribute on
    the request **root** span rather than doubled into a child span per page-serve
    (which would multiply span volume on every cached request).

## [1.0.0] - 2026-07-15

First stable release, tracking the `cboxdk/laravel-telemetry` 1.0 line. The
Statamic overlay — content-aware trace names, Stache / static-cache / Glide
instrumentation, site context and user attribution — is now covered by SemVer.

### Changed

- **Require `cboxdk/laravel-telemetry ^1.0`** (was `^0.2.0 || ^0.3.0`). Verified
  against telemetry 1.0.0; the test suite passes unchanged (73 tests). Supersedes
  the untagged 0.2.2, which only widened the constraint to `^0.3.0`.

## [0.2.1] - 2026-07-13

### Changed

- Docs restructured into the cboxdk topic-folder layout. No code changes.

## [0.2.0] - 2026-07-06

First tagged release without a pre-release suffix, tracking
`cboxdk/laravel-telemetry` v0.2.0 (whose public API surface is now
considered stable enough for production pilots). No `minimum-stability`
tweak is needed to install the addon any more. Under SemVer 0.x,
breaking changes bump the minor version from here on.

### Changed

- **Requires `cboxdk/laravel-telemetry` ^0.2.0.** The hook surface the
  addon builds on (`resolveRouteUsing`, `enrichRequestsUsing`,
  `classifyCacheKeysUsing`, `resolveUserUsing`, `BrowserSnippet`, events)
  is unchanged from the alpha line, so this is a version-alignment
  release — no addon behaviour changes. Verified green against core
  v0.2.0 (73 tests).

## [0.1.0-alpha.8] - 2026-07-06

### Changed

- **Frontend 404s are bucketed as `not_found`.** A URL that matches
  Statamic's `/{segments?}` catch-all but resolves to no entry, term or
  taxonomy (broken links, bots, stale sitemaps) now gets
  `http.route = not_found` instead of the raw catch-all template, so 404
  traffic is visible and sizeable in route tables and histograms instead of
  diluting the frontend bucket. Scoped to `FrontendController`, so a 404 on
  a real Laravel route keeps its own `http.route`. Cache hits still keep
  `/{segments?}` (served before any content resolves — and fast; they carry
  `statamic.static_cache: hit`). The `resolveRouteUsing` resolver now takes
  the final response, via `Content::route($request, $response)`.

## [0.1.0-alpha.7] - 2026-07-05

### Changed

- The bundled dashboard is now generated by a **PHP** script
  (`resources/grafana/generate.php`) instead of a Python one — no reason to
  carry Python in a PHP package. Output is unchanged; regenerate with
  `php resources/grafana/generate.php`.

### Added

- **Cache purge annotations.** Every stache, static cache and glide cache
  clear now emits an unsampled `statamic.cache.purge` event
  (`cache.type`: stache/static/glide/glide_asset, `cache.trigger`:
  cli/http), and the bundled Statamic dashboard gained annotation lines
  for cache purges (orange) and deploys (purple) — a latency spike after
  a purge now explains itself the same way it does after a deploy.
  Toggle: `instrument.cache_purges` (`STATAMIC_TELEMETRY_CACHE_PURGES`,
  on by default).

## [0.1.0-alpha.6] - 2026-07-05

### Changed

- The CP nav item now points at the **in-app `cboxdk/laravel-telemetry-ui`
  dashboard** automatically when that package is installed (it has
  dedicated Statamic pages) — instead of only an external Grafana URL. The
  config key is renamed `cp.grafana_url` → `cp.url`
  (`STATAMIC_TELEMETRY_GRAFANA_URL` → `STATAMIC_TELEMETRY_UI_URL`; the old
  env is still read as a fallback). An explicit URL overrides the
  auto-detected one; a cross-origin URL opens in a new tab, the in-app one
  in the same tab.

## [0.1.0-alpha.5] - 2026-07-05

### Added

- **Browser RUM in Antlers.** A `telemetry` tag exposes laravel-telemetry's
  browser tracing to Antlers templates: `{{ telemetry:browser }}` (the
  traceparent meta + the RUM script) and `{{ telemetry:traceparent }}` (just
  the meta), the Antlers counterparts to the `@telemetryBrowser` /
  `@telemetryTraceparent` Blade directives. Both empty when the span ingest
  or trace is off. Requires `cboxdk/laravel-telemetry` ^0.1.0-alpha.6.
- **Static-cache safety for browser tracing.** A `BrowserTracingReplacer`
  (a Statamic replacer, like the built-in CSRF one) strips the per-request
  traceparent and `data-session` from cached pages before they are stored,
  so a cache hit never replays one visitor's server trace or analytics
  session to everyone — the RUM self-roots instead. Covers half measure
  (served through PHP from cache) and full measure (compiled to an HTML
  file, served without PHP — self-rooted, no server span).
- **CP nav shortcut to Grafana.** Set `STATAMIC_TELEMETRY_GRAFANA_URL` to
  add a "Telemetry" Control Panel nav item that opens Grafana/telemetry-ui
  in a new tab.

## [0.1.0-alpha.4] - 2026-07-04

### Changed

- Entry saves in `statamic.content.changes` are now labelled by the
  entry's **publish status** (`published` / `draft` / `scheduled` /
  `expired`) instead of a flat `saved`, so the counter shows the
  publish-state mix of editing activity. It's a status snapshot, not a
  transition — Statamic syncs the pre-save published value away before the
  event fires, so publish/unpublish can't be detected reliably at the
  event boundary. `EntryDeleted` stays `deleted`.
- Dashboard: split the security row into "Entry saves by publish status",
  "Statamic auth & security events" and "Logins & failures" (the last from
  the base package's `auth.events` — login/logout/failed/lockout, which
  laravel-telemetry v0.1.0-alpha.4 now emits, so the addon doesn't
  duplicate it).

## [0.1.0-alpha.3] - 2026-07-04

### Changed

- **`http.route` now carries the logical content route.** Instead of a
  parallel `statamic.route` metric label (which only helped consumers that
  knew to group by it), the addon uses laravel-telemetry's new
  `resolveRouteUsing()` hook to override `http.route` itself with
  `entry:{collection}.{blueprint}` / `term:{taxonomy}` / `taxonomy:{handle}`.
  Route tables, Grafana and TraceQL now group by content with no
  per-consumer change; the raw catch-all is preserved as
  `http.route.template`. Requires `cboxdk/laravel-telemetry` ^0.1.0-alpha.4.
  The separate `statamic.route` label is removed, as is the addon's
  `nameRequestsUsing` registration (the span name derives from the route).

### Added

- Taxonomy index/listing pages are now content-named (`GET taxonomy:{taxonomy}`)
  with `statamic.type=taxonomy` — distinct from a single term page
  (`term:{taxonomy}`). Completes the set of frontend-routable data types
  (entry, term, taxonomy index); assets, globals and navigations aren't
  frontend pages and stay counter-only.

## [0.1.0-alpha.2] - 2026-07-03

### Added

- `statamic.route` metric label on the request-duration histogram — a
  bounded per-collection/taxonomy dimension (`entry:{collection}.{blueprint}`
  / `term:{taxonomy}`), so latency no longer collapses into the single
  `http.route` catch-all template. New dashboard row "Frontend latency by
  content route". The addon now also claims `labelRequestsUsing`; apps with
  their own request metric labels compose via `Content::routeLabel`.

### Compatibility

- Verified against `cboxdk/laravel-telemetry` v0.1.0-alpha.2. Its env-var
  renames don't affect the addon: it reads config **keys** (unchanged), and
  its own env vars are all `STATAMIC_TELEMETRY_*`.

## [0.1.0-alpha.1] - 2026-07-03

First public release. **Alpha** — tracks `cboxdk/laravel-telemetry`'s alpha
line; the metric and attribute surface may still change before 0.1.0.
Statamic 6 only.

- Content-aware request span names behind Statamic's catch-all route
  (`GET entry:{collection}.{blueprint}`, `GET term:{taxonomy}`) with
  entry/term/site attributes on the root span.
- Site context as an ambient dimension, propagated to queued jobs.
- Statamic user attribution: `enduser.roles`, `enduser.groups`,
  `enduser.super`.
- Static cache instrumentation (tracing subclasses of the file and
  application cachers): hit/miss/write on the root span, operation
  counters, and stripping of the trace id response header before the
  half-measure cacher snapshots headers.
- Stache cache-key classification into bounded groups, warm/clear
  counters and warm duration histogram.
- Glide, form submission and content change counters.
- Opt-in Antlers render detail spans (per view) and per-tag spans via
  Statamic's runtime tracing hook.
- Blink memoization tallies (hits/misses) on the trace root span.
- Search index update counter.
- Auth/security event counter (impersonation, 2FA, registrations,
  password changes) and Glide cache-clear counter.
- Content-change coverage extended to revisions, trees, global
  variables, localized terms, roles/groups, fieldsets, asset
  replace/reupload/references, sites, submissions deleted, scheduled
  entries and duplicate-id regeneration.
- Opt-in observable gauges: entries per collection, assets per
  container, user count.
- Bundled Grafana dashboard (`statamic-telemetry:dashboards`) joining
  the core suite's tab bar.
- Every event listener extends a `GuardedListener` base wrapping its body
  in FailSafe, so a listener can never throw into the Statamic operation
  it observes.
- Full docs: reference catalog (attributes, metrics, config, source
  events) and design notes.

