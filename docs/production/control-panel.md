---
title: Control Panel
description: The native Telemetry pages in the Statamic Control Panel — what they show, how they are wired, what the backend must answer
weight: 10
---

# Telemetry in the Control Panel

The addon ships a native telemetry UI inside the Statamic Control Panel:
Vue/Inertia pages built on `@statamic/cms/ui`, compiled to `resources/dist`
and committed, so an install needs no Node. They read back from the telemetry
backend you already send to — **telemetryd** or an **LGTM** stack — over its
Loki, Tempo and Prometheus query APIs. Nothing new to run.

Every page follows one rule: **a number is never the end of the road.** Each
aggregate (a tile, a chart, a breakdown table) sits above the rows it counts —
every request, span, log record or content change in the window — and every
row opens its trace in a slide-in drawer. Breakdown rows and chips filter the
rows below them; rows link to the entry they served.

## Pages

The **Telemetry** sidebar item (Tools) opens the Overview; every telemetry
page shares one in-page navigation, so it never disappears:

| Group | Page | Aggregate on top | Rows underneath |
|---|---|---|---|
| | **Overview** | requests by status class, latency (avg/p50/p95/p99) with period deltas, exceptions, deploy markers, routes needing attention | recent requests |
| | **Explore** | stat header, throughput, latency × time heatmap | requests / traces / logs / errors with facets, group-by, search and live tail |
| Activity | **Requests** | status mix, latency, routes needing attention | every server span — path · title · `entry:collection` · cache outcome · status |
| | **Jobs** | processed / dispatched / p95 / worker utilisation, by job and queue | every run, with queue, attempt and failures |
| | **Queries** | totals with N+1 and duplicate counts, by connection | every `db.query` span with its SQL and repeat count |
| | **Exceptions** | error records by type, bucketed over the window | every record with message and `file:line`, expandable |
| | **Outgoing** | totals, error rate, p95, by host | every client span with method, URL and status |
| Statamic | **Static cache** | hit ratio, hits / misses / writes, by collection | every frontend request with its outcome |
| | **Stache** | warms, clears, warm p95 | every warm and clear, in its request |
| | **Glide** | generations by preset, cache clears | every generation with image path and preset |
| | **Forms** | submissions by form | every submission |
| | **Content** | changes by action and type | the content audit trail — who changed what, click to edit |

**Telemetry doctor** (sidebar only) is the read-only health page: switches,
OTLP endpoint, exporters, spool depth, and a live probe of the backend.

### The drawer

A request opens in Statamic's own slide-in `Stack`: status, root name,
duration, services, then the **waterfall** — 28px rows, category-coloured
bars (db, cache, queue, view, http, app), a ruler — and, when the backend
linked any, the trace's **log lines**. Clicking a span opens a nested stack
with its attributes; every value has a menu to copy it, filter Explore by it
(route, method, status, collection, taxonomy, type, site, cache, path) or
open it when it is a URL. From the request span the drawer offers *Requests
for this route*, *Explore this path*, *Edit entry* and *View page*.

The drawer is part of the URL (`?drawer=trace:<id>`), so a trace deep-links,
survives a reload and stacks (`trace:a~trace:b`). `Esc` pops one level;
`j`/`k` step through the Explore list. Opening a drawer is a client-side URL
change — the page's props are not refetched.

### Time

The topbar carries the period (15m · 1h · 6h · 24h · 7d), a custom range
picker (prefilled with the window you are looking at; `from`/`to` in the
URL), auto-refresh (off / 10 / 30 / 60 s), and the **time zone** every
timestamp is shown in — your browser's (named, e.g. `GMT+1`) or UTC, kept in
`localStorage`. Chart axes carry the date once a window spans more than a
day and a half. Dragging a range on a chart zooms every page to it.

## The entry's Telemetry tab

Every entry blueprint gets a full-width **Telemetry** tab (added on
`EntryBlueprintFound` for users who may view telemetry; the field is
`computed`, so nothing is ever saved with the entry). It is that page's own
story over 24h / 7d / 30d:

- **Traffic** — requests with the status mix, a period delta and a chart.
- **Latency** — avg / p50 / p95 with delta, and the site's p95 beside it
  (*"this page is 1.3× slower"*).
- **Static cache** — hit ratio, hits / misses / writes, when it was last
  written (a save or an invalidation).
- **Exceptions on this page** — error records logged against its path.
- **Recent requests** — each opening its trace in the drawer, in the editor.
- **Change history** — the audit trail for this entry.

*Open in Telemetry* jumps to the Requests page filtered to the entry's path.
The tab fetches its story from `telemetry/entry/{id}` after the editor
opens, so the publish form never waits on the backend. Turn the tab off with
`STATAMIC_TELEMETRY_ENTRY_TAB=false`.

## Permissions

| Permission | Grants |
|---|---|
| **View the telemetry dashboard** (`view telemetry`) | every telemetry page, the drawer, the entry tab |
| **View content audit log** | the rows of the Content page (the aggregate needs only the one above) |
| **View telemetry doctor** | the doctor |

## Configuration

```dotenv
# The backend's query base URL — telemetryd, or the Loki/Tempo/Prometheus
# gateway of an LGTM stack. Falls back to laravel-telemetry-ui's TELEMETRY_UI_LOGS_URL.
STATAMIC_TELEMETRY_AUDIT_LOGS_URL=http://localhost:4319
# STATAMIC_TELEMETRY_AUDIT_LOGS_TOKEN=...      # bearer token
# STATAMIC_TELEMETRY_AUDIT_LOGS_TENANT=...     # X-Scope-OrgID

# Keep the telemetry pages' own requests (and their calls to the backend) out
# of the app's traces and metrics. On by default — otherwise they are the
# busiest route and host in the very pages that display them.
STATAMIC_TELEMETRY_CP_IGNORE_OWN_ROUTES=true

# The Telemetry tab on every entry.
STATAMIC_TELEMETRY_ENTRY_TAB=true

# Set to turn the Telemetry item back into a link-out (a Grafana dashboard or a
# remote laravel-telemetry-ui) instead of the native pages.
# STATAMIC_TELEMETRY_UI_URL=
```

## What the backend must answer

| Page needs | API | Notes |
|---|---|---|
| Request rows, waterfalls, span rows | Tempo `/api/search` (TraceQL `q`, `limit`, `start`/`end`), `/api/traces/{id}` | The lists match the *server span* (`{kind=server}`) and read its attributes from the response's `spanSets`; span pages use `{name="…"}` / `{kind=consumer}` / `{kind=client}`. |
| Tiles, charts, breakdowns | Prometheus `/api/v1/query`, `/api/v1/query_range` | `increase()` over the window and per step; `histogram_quantile` for p95. telemetryd caps how many records one query may scan — beyond a few hours the p95 *series* is left out rather than drawn as zero. |
| Logs, exceptions, content audit | Loki `/api/v1/query_range` | Events are the line; attributes are structured metadata (`exception_type`, `url_path`, `statamic_content_*`). |

Two things depend on the backend and are known to be missing on telemetryd
today: log lines linked to a trace (it does not index the OTLP log
`traceId` as `trace_id`, so the drawer's Logs tab stays hidden) and the
request name on span rows (its `rootTraceName` is the matched span, not the
root). Both degrade quietly.

## Request naming, as the pages show it

Frontend requests are named by the content they served, not the catch-all
route: `GET entry:pages.page`, `GET term:topics`, `GET not_found`,
`GET glide:assets`. A **static-cache hit** is named the same way — the
addon looks the URL up when the cache answered before the controller ran —
so a cached page and an uncached one are the same route in every table. The
span carries the page's `statamic.content.title`, `statamic.content.url` and
`statamic.content.edit_url`, which is what lets a row say *About ·
entry:pages · cache hit* and link to the entry.
