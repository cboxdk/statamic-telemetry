<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Tags;

use Cbox\Telemetry\Http\BrowserSnippet;
use Statamic\Tags\Tags;

/**
 * Antlers access to laravel-telemetry's browser RUM, for apps that render
 * with Antlers rather than Blade (where `@telemetryBrowser` /
 * `@telemetryTraceparent` live).
 *
 *   {{ telemetry:browser }}      the full RUM snippet (traceparent meta +
 *                                the <script> tag) — empty when the span
 *                                ingest is disabled.
 *   {{ telemetry:traceparent }}  just the <meta name="traceparent"> so the
 *                                browser roots on the server trace — empty
 *                                when no trace is active.
 *
 * Both return raw HTML. Under static caching the per-request bits (the
 * traceparent and data-session) are stripped from the cached copy by
 * BrowserTracingReplacer, so they are never replayed to other visitors.
 */
class Telemetry extends Tags
{
    /**
     * {{ telemetry:browser }}
     */
    public function browser(): string
    {
        return BrowserSnippet::render();
    }

    /**
     * {{ telemetry:traceparent }} — same output as the @telemetryTraceparent
     * Blade directive.
     */
    public function traceparent(): string
    {
        $traceparent = app('telemetry')->traceparent();

        return is_string($traceparent)
            ? '<meta name="traceparent" content="'.e($traceparent).'">'
            : '';
    }
}
