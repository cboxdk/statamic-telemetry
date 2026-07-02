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
    | user            enduser.roles / enduser.groups / enduser.super on request
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
    | forms           Counter per created submission, labelled by form.
    | content_events  Counter for content changes (entry/term/asset/… saved,
    |                 deleted, uploaded).
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
        'forms' => env('STATAMIC_TELEMETRY_FORMS', true),
        'content_events' => env('STATAMIC_TELEMETRY_CONTENT_EVENTS', true),
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

];
