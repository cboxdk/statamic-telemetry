<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Http\Controllers\Cp;

use Cbox\StatamicTelemetry\Cp\TelemetryNav;
use Cbox\StatamicTelemetry\Telemetry\ActivityMetrics;
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
 * The native "Telemetry" CP dashboard that replaces the link-out to
 * laravel-telemetry-ui. The Overview is a card grid built from metrics
 * (requests split by status class, latency, exceptions and the routes that
 * need attention) with deploy markers overlaid; Activity covers queue / DB /
 * storage / outgoing-HTTP work; the Statamic section covers the addon's own
 * signals. Traces open in a drawer (see {@see TraceController}). Thin — the
 * assemblers compute everything, {@see TimeRange} owns the window / custom
 * range and {@see TelemetryNav} the in-page navigation.
 */
class TelemetryController extends CpController
{
    /** The page's sections (tabs). Value → label. */
    public const SECTIONS = [
        'overview' => 'Overview',
        'activity' => 'Activity',
        'statamic' => 'Statamic',
    ];

    public function __invoke(Request $request, Requests $requests, TelemetryMetrics $metrics, Exceptions $exceptions, Deployments $deployments, ActivityMetrics $activity, TelemetryNav $nav): Response
    {
        $range = TimeRange::fromRequest($request);
        $seconds = $range->seconds;
        $endsAt = $range->endsAt;
        $section = $this->section($request);
        $overview = $section === 'overview';

        return Inertia::render('statamic-telemetry::Telemetry', [
            'section' => $section,
            'sections' => self::SECTIONS,
            'nav' => $nav->items($section),
            ...$range->toProps(),
            'dashboardUrl' => cp_route('telemetry.dashboard'),
            'exploreUrl' => cp_route('telemetry.explore'),
            'overview' => $overview ? $requests->overview($seconds, $endsAt) : null,
            'exceptions' => $overview ? $exceptions->overview($seconds, $endsAt) : null,
            // Deploy markers belong on every timeline, Activity's included.
            'deployments' => $section !== 'statamic' ? $deployments->window($seconds, 50, $endsAt) : [],
            'activity' => $section === 'activity' ? $activity->overview($seconds, $endsAt) : null,
            // ~20 PromQL queries — not for a section that shows none of them.
            'metrics' => $section === 'activity' ? null : $metrics->overview($seconds, $endsAt),
        ]);
    }

    private function section(Request $request): string
    {
        $section = (string) $request->query('section', 'overview');

        return isset(self::SECTIONS[$section]) ? $section : 'overview';
    }
}
