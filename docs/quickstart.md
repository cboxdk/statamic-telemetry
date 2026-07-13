---
title: Quickstart
description: Install the addon, publish the config and import the Grafana dashboard
weight: 2
---

# Quickstart

`cboxdk/statamic-telemetry` is a thin overlay on
[`cboxdk/laravel-telemetry`](https://github.com/cboxdk/laravel-telemetry). It
adds Statamic's vocabulary to the signals the base package already captures —
content-named request spans, the Stache and static cache, site and user
context — and ships Statamic-specific metrics. See the
[Requirements](requirements.md) before you begin.

## 1. Install

```bash
composer require cboxdk/statamic-telemetry
```

Everything is on by default **except** the Antlers view/tag spans and the
inventory gauges (both add per-request or per-scrape cost).

## 2. Publish the config (optional)

Only needed if you want to change the `instrument.*` toggles or opt into the
gauges/Antlers spans:

```bash
php artisan vendor:publish --tag=statamic-telemetry-config
```

Every toggle is also settable via a `STATAMIC_TELEMETRY_*` env var — see the
[configuration reference](configuration/reference.md) for the full list.

## 3. Import the Grafana dashboard

The addon bundles a **Statamic** dashboard that joins the base suite's tab bar
(same `telemetry` tag):

```bash
php artisan statamic-telemetry:dashboards            # import into a local Grafana
php artisan statamic-telemetry:dashboards --export=./provisioning
```

## 4. (Optional) Browser tracing in Antlers

Drop the RUM snippet into your layout's `<head>` to root browser page-load
timing on the server trace:

```antlers
{{ telemetry:browser }}
```

It stays correct under static caching automatically — see
[Browser tracing in Antlers](production/browser-tracing.md).

## Next steps

- [Reference](configuration/reference.md) — every span attribute, metric and
  config toggle, and the events each derives from.
- [Design notes](core-concepts/design-notes.md) — why the addon is built the
  way it is.
