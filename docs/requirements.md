---
title: Requirements
description: The PHP, Statamic and package versions the Composer resolver enforces
weight: 3
---

# Requirements

These are the constraints declared in the package's `composer.json`. Composer
will refuse to install if they are not met.

| Requirement | Constraint | Why |
|---|---|---|
| PHP | `^8.3 \| ^8.4 \| ^8.5` | The addon targets modern PHP; same floor as the base package. |
| `statamic/cms` | `^6.0` | Statamic 6 is the host application it instruments. |
| `cboxdk/laravel-telemetry` | `^0.2.0` | The base telemetry package this addon overlays — it supplies the exporters, resolver hooks and `Tracer`/`Telemetry` APIs. |

The addon ships no exporter or store of its own. Signals leave through whatever
`cboxdk/laravel-telemetry` is configured with (OTLP, Prometheus), so that
package's own transport requirements apply downstream.

## Development-only

These are needed to build and test the package, not to run it:

- `laravel/pint` `^1.14` — code style
- `larastan/larastan` `^3.0` — static analysis
- `pestphp/pest` `^4.0` and `pestphp/pest-plugin-laravel` `^4.0` — test runner
- `orchestra/testbench` `^10.0` — Laravel test harness
- `nunomaduro/collision` `^8.8` — console error reporting
