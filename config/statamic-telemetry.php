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
    | views           A detail span per rendered Antlers view. Off by
    |                 default: verbose, and it wraps the view engine.
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
        'views' => env('STATAMIC_TELEMETRY_VIEWS', false),
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
