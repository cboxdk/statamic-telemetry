---
title: Browser tracing in Antlers
description: The telemetry Antlers tags, and how they stay correct under static caching
weight: 31
---

# Browser tracing in Antlers

`cboxdk/laravel-telemetry` ships browser RUM (real user monitoring) — a
zero-build script ([`@cboxdk/telemetry-browser`](https://www.npmjs.com/package/@cboxdk/telemetry-browser))
that reports page-load timing, `fetch` calls and JS errors, and roots them
on the server trace. In Blade you drop it in with `@telemetryBrowser`. This
addon exposes the same thing to **Antlers** templates.

## The tags

```antlers
{{# In your layout's <head> #}}
{{ telemetry:browser }}
```

- `{{ telemetry:browser }}` — the full snippet: the `<meta name="traceparent">`
  (so the browser roots on the current server trace) plus the RUM `<script>`
  tag configured from `telemetry.ingest.spans`. **Empty** when the span
  ingest is off (`telemetry.ingest.spans.enabled`).
- `{{ telemetry:traceparent }}` — just the `<meta name="traceparent">`, for
  when you load the RUM script yourself. **Empty** when no trace is active.

Both return raw HTML; the traceparent value is HTML-escaped.

### A layout `<head>`

```antlers
<!doctype html>
<html>
<head>
    <title>{{ title }}</title>
    {{ telemetry:browser }}
</head>
<body>{{ template_content }}</body>
</html>
```

Enable the ingest (and, optionally, the shared analytics session) in the
app's `.env`:

```dotenv
TELEMETRY_INGEST_SPANS=true
TELEMETRY_ANALYTICS=true        # optional: one session.id across browser + server
```

## Static caching — the important part

The snippet contains two values that are **unique to the request that
rendered the page** and must never be baked into a cached copy:

- the **traceparent** — the id of the server span. Cached, every later
  visitor's RUM would root on one long-gone server trace.
- **`data-session`** — the analytics session id (only present with analytics
  on). Cached, every visitor would share one session.

The addon registers a Statamic **replacer** (`BrowserTracingReplacer`,
alongside the built-in CSRF one) that strips both from the copy **before it
is cached**. So:

| Strategy | Cache hit is served… | What the visitor gets | Trace |
|---|---|---|---|
| **half measure** (`application`) | through PHP, from the cache | the stripped page (no traceparent/session) | RUM **self-roots**; the old server span is not this visit's |
| **full measure** (`file`) | straight off disk by the web server, **no PHP** | the stripped HTML file | RUM **self-roots**; **no server span exists at all** |

Key point about **full measure**: the strip happens when the file is
*compiled* (in PHP, on the cache-warming request), so the HTML file on disk
is already clean — there is no replacer at serve time because there is no
PHP at serve time. Cache hits are "untracked" *server-side* (no span), but
the RUM script still records the page load in the **browser** as a
standalone trace. The one exception is the very first visitor (who triggered
the compile): they ran through PHP, have a real server span, and keep their
traceparent so their RUM roots on it.

You don't configure any of this — the replacer is registered automatically.
It is a no-op on pages that don't use the tags.

## CP shortcut to the telemetry UI

The addon adds a **Telemetry** item to the Control Panel nav pointing at
your telemetry UI:

- If [`cboxdk/laravel-telemetry-ui`](https://github.com/cboxdk/laravel-telemetry-ui)
  is installed, it links to that in-app dashboard automatically — it has
  dedicated Statamic pages (Static Cache, Stache, Glide, Forms, Content,
  Inventory). Opens in the same tab.
- Otherwise, set a URL (Grafana, or a remote telemetry-ui) — it opens in a
  new tab:

  ```dotenv
  STATAMIC_TELEMETRY_UI_URL=https://grafana.example.com/d/cbox-tel-statamic
  ```

Nothing installed and no URL set → no nav item. An explicit
`STATAMIC_TELEMETRY_UI_URL` always overrides the auto-detected one.
