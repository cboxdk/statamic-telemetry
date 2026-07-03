# Security Policy

## Supported versions

The latest release receives security fixes.

## Reporting a vulnerability

Please **do not** open a public issue. Email [sn@cbox.dk](mailto:sn@cbox.dk)
with a description and, if possible, a proof of concept. You will get a
response within a few business days.

Areas of particular interest for this package:

- **Data leakage through metric labels and span attributes.** The addon
  goes to some length to keep labels bounded and to put ids/slugs only on
  span attributes; a path that leaks unbounded or sensitive values into a
  metric label is a bug worth reporting.
- **The trace id response header on cached pages.** The addon strips
  `X-Trace-Id` before Statamic's half-measure cacher snapshots headers, so
  one visitor's trace id is never replayed to others. A path where it
  survives into a cached response is a security-relevant defect.
- **User attribution.** `enduser.roles`/`groups`/`super` are derived from
  the Statamic user; only handles and a boolean are emitted, never PII.

Underlying transport, scrape-endpoint and redaction concerns live in
[cboxdk/laravel-telemetry](https://github.com/cboxdk/laravel-telemetry).
