<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | Disables the Statamic overlay without touching the underlying
    | cboxdk/laravel-telemetry package. Core request/query/queue telemetry
    | keeps working; only the Statamic-specific hooks and listeners stop.
    |
    */

    'enabled' => env('STATAMIC_TELEMETRY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Instrumentation toggles
    |--------------------------------------------------------------------------
    |
    | user            user.roles / user.groups / user.super on request
    |                 spans, for Statamic users (file or eloquent driven).
    | site_context    statamic.site as an ambient dimension on every span in
    |                 the trace — propagates to queued jobs.
    | content         Root span naming (GET entry:{collection}.{blueprint})
    |                 and statamic.entry.id / collection / blueprint /
    |                 taxonomy attributes, resolved from the data Statamic
    |                 bound to the response.
    | static_cache    Hit/miss/write outcome on the root span, operation
    |                 counters, and stripping of the trace response header
    |                 before the half-measure cacher snapshots headers.
    | stache          Cache key classification (stache.index, stache.item, …)
    |                 plus warm/clear counters and warm duration.
    | glide           Counter per generated image, labelled by preset.
    | cache_purges    Unsampled statamic.cache.purge event per stache /
    |                 static / glide cache clear, rendered as annotation
    |                 lines on the bundled dashboard — latency spikes map
    |                 to purges the same way they map to deploys.
    | forms           Counter per created submission, labelled by form.
    | content_events  Counter for content changes (entry/term/asset/… saved,
    |                 deleted, uploaded).
    | content_audit   One `statamic.content.change` log event per change: the
    |                 item (id, title, collection, site, url, edit link), the
    |                 transition (created/updated/published/unpublished/…),
    |                 the changed fields and who made it — an audit trail.
    |                 Needs content_events.
    | search          Counter per search index update, labelled by index.
    | auth            Counter for auth/security events: impersonation,
    |                 2FA (enabled/failed/passed/…), registrations and
    |                 password changes. No user ids on the metric.
    | blink           statamic.blink.hits / statamic.blink.misses tallies on
    |                 the trace root span — the request's memoization
    |                 effectiveness (augmentation caching lives in Blink).
    | views           A detail span per rendered Antlers view. Off by
    |                 default: verbose, and it wraps the view engine.
    | antlers         A detail span per Antlers *tag* invocation
    |                 (collection, nav, form, partial) via Statamic's
    |                 runtime tracing hook. Off by default: forces
    |                 statamic.antlers.tracing on, which has a per-node
    |                 cost across all rendering.
    |
    */

    'instrument' => [
        'user' => env('STATAMIC_TELEMETRY_USER', true),
        'site_context' => env('STATAMIC_TELEMETRY_SITE', true),
        'content' => env('STATAMIC_TELEMETRY_CONTENT', true),
        'static_cache' => env('STATAMIC_TELEMETRY_STATIC_CACHE', true),
        'stache' => env('STATAMIC_TELEMETRY_STACHE', true),
        'glide' => env('STATAMIC_TELEMETRY_GLIDE', true),
        'cache_purges' => env('STATAMIC_TELEMETRY_CACHE_PURGES', true),
        'forms' => env('STATAMIC_TELEMETRY_FORMS', true),
        'content_events' => env('STATAMIC_TELEMETRY_CONTENT_EVENTS', true),
        'content_audit' => env('STATAMIC_TELEMETRY_CONTENT_AUDIT', true),
        'search' => env('STATAMIC_TELEMETRY_SEARCH', true),
        'auth' => env('STATAMIC_TELEMETRY_AUTH', true),
        'blink' => env('STATAMIC_TELEMETRY_BLINK', true),
        'views' => env('STATAMIC_TELEMETRY_VIEWS', false),
        'antlers' => env('STATAMIC_TELEMETRY_ANTLERS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Observable gauges
    |--------------------------------------------------------------------------
    |
    | Pull-based gauges evaluated at scrape/flush time: entries per
    | collection, assets per container, user count. They query the Stache
    | on every scrape, so they are opt-in.
    |
    */

    'gauges' => [
        'enabled' => env('STATAMIC_TELEMETRY_GAUGES', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Control Panel
    |--------------------------------------------------------------------------
    |
    | url    A "Telemetry" item in the CP nav linking to your telemetry UI.
    |        When cboxdk/laravel-telemetry-ui is installed it points at that
    |        dashboard automatically (it has dedicated Statamic pages); set
    |        `url` to override with a Grafana or remote telemetry-ui URL. No UI
    |        installed and no url set → no nav item.
    |
    | audit  A native "Content audit" CP page that reads the
    |        `statamic.content.change` records back from the telemetry backend
    |        (telemetryd or an LGTM stack — both answer the Loki query API) and
    |        lists them in the Control Panel itself. No telemetry-ui, no React.
    |        It is shown only to users with the "View content audit log"
    |        permission, and only when a logs URL resolves.
    |
    |        url            The logs backend's base URL — telemetryd, or Loki
    |                       (a Grafana datasource proxy works too). Falls back
    |                       to telemetry-ui's own env so a site already running
    |                       it needs no new config. No URL → no audit page.
    |        token / tenant Optional bearer token and X-Scope-OrgID tenant,
    |                       as the backend requires.
    |        service        The `service.name` to match; defaults to this app's
    |                       telemetry service name. service_label is the Loki
    |                       stream label it landed on (service_name by default).
    |        selector       A full LogQL stream selector to override the one
    |                       built from service/service_label, e.g. for an
    |                       unusual label scheme.
    |        window / limit Default look-back and the newest-N records pulled
    |                       from the backend before filtering in PHP.
    |        enabled        Force the page off (null/true = on when a URL
    |                       resolves).
    |
    */

    'cp' => [
        'url' => env('STATAMIC_TELEMETRY_UI_URL', env('STATAMIC_TELEMETRY_GRAFANA_URL')),

        // Keep the telemetry pages themselves (and their calls to the
        // backend) out of the app's own traces and metrics.
        'ignore_own_routes' => env('STATAMIC_TELEMETRY_CP_IGNORE_OWN_ROUTES', true),

        // Add a full-width "Telemetry" tab to every entry blueprint (the
        // entry's own requests, latency, cache, exceptions and changes).
        'entry_tab' => env('STATAMIC_TELEMETRY_ENTRY_TAB', true),

        'audit' => [
            'enabled' => env('STATAMIC_TELEMETRY_AUDIT_UI'),
            'url' => env('STATAMIC_TELEMETRY_AUDIT_LOGS_URL', env('TELEMETRY_UI_LOGS_URL')),
            'token' => env('STATAMIC_TELEMETRY_AUDIT_LOGS_TOKEN', env('TELEMETRY_UI_TOKEN')),
            'tenant' => env('STATAMIC_TELEMETRY_AUDIT_LOGS_TENANT', env('TELEMETRY_UI_LOGS_TENANT')),
            'service' => env('STATAMIC_TELEMETRY_AUDIT_SERVICE'),
            'service_label' => env('STATAMIC_TELEMETRY_AUDIT_SERVICE_LABEL', 'service_name'),
            'selector' => env('STATAMIC_TELEMETRY_AUDIT_SELECTOR'),
            'window' => env('STATAMIC_TELEMETRY_AUDIT_WINDOW', '24h'),
            'limit' => env('STATAMIC_TELEMETRY_AUDIT_LIMIT', 1000),
            'timeout' => env('STATAMIC_TELEMETRY_AUDIT_TIMEOUT', 5),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Content audit
    |--------------------------------------------------------------------------
    |
    | What the `statamic.content.change` records carry about who and what.
    | actor_name      The editor's name beside their user.id — these are the
    |                 site's own editors, and an audit trail of ids nobody can
    |                 read is not much of one. Off → the id only (the base
    |                 package's default). The email is never sent.
    | changed_fields  The top-level field handles an update changed (names
    |                 only, never values).
    |
    */

    'audit' => [
        'actor_name' => env('STATAMIC_TELEMETRY_AUDIT_ACTOR_NAME', true),
        'changed_fields' => env('STATAMIC_TELEMETRY_AUDIT_CHANGED_FIELDS', true),
    ],

];
