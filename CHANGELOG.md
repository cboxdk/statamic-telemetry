# Changelog

All notable changes to `cboxdk/statamic-telemetry` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

