<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Cp;

use Illuminate\Support\Facades\Route;

/**
 * Resolves where the "Telemetry" CP nav item points.
 *
 * Prefers the in-app cboxdk/laravel-telemetry-ui dashboard — which has
 * dedicated Statamic pages (Static Cache, Stache, Glide, …) — when that
 * package is installed. An explicit `cp.url` (a Grafana or remote
 * telemetry-ui URL) overrides it. Nothing configured and no telemetry-ui
 * installed → no nav item.
 */
final class NavLink
{
    public static function url(): ?string
    {
        $configured = config('statamic-telemetry.cp.url');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        // The bundled UI, when present — routed by laravel-telemetry-ui. 2.x+
        // serves every dashboard path from one SPA route (`telemetry-ui.spa`);
        // 1.x named its pages route `telemetry-ui.page`. Checking only the
        // old name dropped the nav item for everyone on 2.x and later.
        foreach (['telemetry-ui.spa', 'telemetry-ui.page'] as $name) {
            if (Route::has($name)) {
                return route($name);
            }
        }

        return null;
    }

    /**
     * Open a cross-origin link (e.g. Grafana) in a new tab; keep an in-app
     * link (the telemetry-ui dashboard, same host) in the current one.
     */
    public static function opensInNewTab(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $host !== null && $host !== request()->getHost();
    }
}
