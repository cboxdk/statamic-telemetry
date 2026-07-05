---
title: Statamic Telemetry
description: A Statamic 6 overlay that teaches cboxdk/laravel-telemetry Statamic's vocabulary
---

# Statamic Telemetry

`cboxdk/statamic-telemetry` is a Statamic 6 addon on top of
[`cboxdk/laravel-telemetry`](https://github.com/cboxdk/laravel-telemetry).
The base package already traces HTTP requests, queries, queue jobs, the
Laravel cache and more. This addon teaches those signals Statamic's
vocabulary — content-named request spans, the Stache and static cache,
site and user context — and adds Statamic-specific metrics.

It is a thin overlay: everything it does is built on the base package's
public extension points (resolver hooks, the `TelemetryProvider` contract,
`Tracer::recordSpan`/`bumpStat`, `Telemetry::context`). It ships no
exporter or store of its own — signals flow out through whatever the base
package is configured with (OTLP, Prometheus).

## Documentation

- **[Reference](reference.md)** — the canonical catalog: every span
  attribute, every metric, every config toggle, and the exact events each
  one derives from. Read this to know what you get.
- **[Design notes](design-notes.md)** — why the addon is built the way it
  is: the catch-all routing problem, subclassed cachers, the header-strip
  fix, Blink and Antlers instrumentation, and the invariants that keep
  telemetry from ever breaking a Statamic operation.
- **[Browser tracing in Antlers](browser-tracing.md)** — the
  `{{ telemetry:browser }}` / `{{ telemetry:traceparent }}` tags and how
  they stay correct under half- and full-measure static caching.

## Install

```bash
composer require cboxdk/statamic-telemetry
```

Requires PHP 8.3+, Statamic 6 and `cboxdk/laravel-telemetry`. Everything is
on by default except the Antlers view/tag spans and the inventory gauges.
Publish the config to change toggles:

```bash
php artisan vendor:publish --tag=statamic-telemetry-config
```

## Grafana

The addon bundles a **Statamic** dashboard that joins the base suite's tab
bar (same `telemetry` tag):

```bash
php artisan statamic-telemetry:dashboards            # import into a local Grafana
php artisan statamic-telemetry:dashboards --export=./provisioning
```

## At a glance

| What | How | Default |
|---|---|---|
| Content-named request spans | `nameRequestsUsing` resolver reading `ResponseCreated` | on |
| Entry/term/site attributes | `enrichRequestsUsing` resolver | on |
| Site as ambient context | `RouteMatched` listener → `Telemetry::context` | on |
| User roles/groups/super | `resolveUserUsing` resolver | on |
| Static cache outcomes + counters | tracing cacher subclasses | on |
| Stache key groups + warm metrics | cache-key classifier + events | on |
| Content / forms / glide / search / auth counters | event listeners | on |
| Blink memoization tallies | tracing Blink subclass | on |
| Inventory gauges | `TelemetryProvider` (opt-in) | off |
| Antlers view + tag spans | view engine wrap + runtime tracer (opt-in) | off |
