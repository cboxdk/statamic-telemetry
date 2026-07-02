# Changelog

All notable changes to `cboxdk/statamic-telemetry` are documented here.

## Unreleased

Initial release.

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
- Opt-in Antlers render detail spans.
- Opt-in observable gauges: entries per collection, assets per
  container, user count.
- Bundled Grafana dashboard (`statamic-telemetry:dashboards`) joining
  the core suite's tab bar.
