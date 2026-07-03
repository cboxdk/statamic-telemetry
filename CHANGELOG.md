# Changelog

All notable changes to `cboxdk/statamic-telemetry` are documented here.

## Unreleased

Initial release. Statamic 6 only.

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

