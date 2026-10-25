<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Http\Controllers\Cp;

use Cbox\StatamicTelemetry\Cp\TelemetryNav;
use Cbox\StatamicTelemetry\Telemetry\Deployments;
use Cbox\StatamicTelemetry\Telemetry\Exceptions;
use Cbox\StatamicTelemetry\Telemetry\Requests;
use Cbox\StatamicTelemetry\Telemetry\TelemetryMetrics;
use Cbox\StatamicTelemetry\Telemetry\TimeRange;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Statamic\Http\Controllers\CP\CpController;

/**
 * The native "Telemetry" Overview — the dashboard that replaces the link-out
 * to laravel-telemetry-ui: a card grid built from metrics (requests split by
 * status class, latency, exceptions and the routes that need attention) with
 * deploy markers overlaid. Every area has its own page beyond this (see
 * {@see PageController}); traces open in a drawer (see {@see TraceController}).
 * Thin — the assemblers compute everything, {@see TimeRange} owns the window
 * / custom range and {@see TelemetryNav} the in-page navigation.
 */
class TelemetryController extends CpController
{
    public function __invoke(Request $request, Requests $requests, TelemetryMetrics $metrics, Exceptions $exceptions, Deployments $deployments, TelemetryNav $nav): Response
    {
        $range = TimeRange::fromRequest($request);
        $seconds = $range->seconds;
        $endsAt = $range->endsAt;

        return Inertia::render('statamic-telemetry::Telemetry', [
            'nav' => $nav->items('overview'),
            ...$range->toProps(),
            'dashboardUrl' => cp_route('telemetry.dashboard'),
            'exploreUrl' => cp_route('telemetry.explore'),
            'overview' => $requests->overview($seconds, $endsAt),
            'exceptions' => $exceptions->overview($seconds, $endsAt),
            'deployments' => $deployments->window($seconds, 50, $endsAt),
            'metrics' => $metrics->overview($seconds, $endsAt),
        ]);
    }
}
