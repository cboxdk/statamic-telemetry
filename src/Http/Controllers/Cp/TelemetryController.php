<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Http\Controllers\Cp;

use Cbox\StatamicTelemetry\Telemetry\ActivityMetrics;
use Cbox\StatamicTelemetry\Telemetry\Deployments;
use Cbox\StatamicTelemetry\Telemetry\Exceptions;
use Cbox\StatamicTelemetry\Telemetry\Requests;
use Cbox\StatamicTelemetry\Telemetry\TelemetryMetrics;
use Cbox\StatamicTelemetry\Telemetry\Traces;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Statamic\Http\Controllers\CP\CpController;

/**
 * The native "Telemetry" CP dashboard that replaces the link-out to
 * laravel-telemetry-ui. The Overview is a card grid built from metrics
 * (requests split by status class, latency, exceptions and the routes that
 * need attention) with deploy markers overlaid; the Statamic section covers
 * the addon's own signals. A `trace` query shows a single waterfall. Thin —
 * the assemblers compute everything.
 */
class TelemetryController extends CpController
{
    /** Look-back windows offered on the page. Value → label. */
    public const WINDOWS = [
        '5m' => 'Last 5 min',
        '15m' => 'Last 15 min',
        '30m' => 'Last 30 min',
        '1h' => 'Last hour',
        '3h' => 'Last 3 hours',
        '6h' => 'Last 6 hours',
        '12h' => 'Last 12 hours',
        '24h' => 'Last 24 hours',
    ];

    /** The page's sections (tabs). Value → label. */
    public const SECTIONS = [
        'overview' => 'Overview',
        'activity' => 'Activity',
        'statamic' => 'Statamic',
    ];

    public function __invoke(Request $request, Requests $requests, Traces $traces, TelemetryMetrics $metrics, Exceptions $exceptions, Deployments $deployments, ActivityMetrics $activity): Response
    {
        if (is_string($trace = $request->query('trace')) && $trace !== '') {
            return Inertia::render('statamic-telemetry::Trace', [
                'traceId' => $trace,
                'spans' => $traces->trace($trace),
            ]);
        }

        $window = $this->window($request);
        $section = $this->section($request);
        $overview = $section === 'overview';
        [$seconds, $endsAt] = $this->range($request, $window);

        return Inertia::render('statamic-telemetry::Telemetry', [
            'section' => $section,
            'sections' => self::SECTIONS,
            'window' => $window,
            'windows' => self::WINDOWS,
            'from' => $endsAt !== null ? $endsAt - $seconds : null,
            'to' => $endsAt,
            'dashboardUrl' => cp_route('telemetry.dashboard'),
            'exploreUrl' => cp_route('telemetry.explore'),
            'overview' => $overview ? $requests->overview($seconds, $endsAt) : null,
            'exceptions' => $overview ? $exceptions->overview($seconds, $endsAt) : null,
            'deployments' => $overview ? $deployments->window($seconds, 50, $endsAt) : [],
            'activity' => $section === 'activity' ? $activity->overview($seconds, $endsAt) : null,
            'metrics' => $metrics->overview($seconds, $endsAt),
        ]);
    }

    /**
     * The time range to query: an explicit brushed `from`/`to` (unix seconds)
     * when valid, otherwise the look-back window anchored at now.
     *
     * @return array{0: int, 1: ?int} [windowSeconds, endsAt|null]
     */
    private function range(Request $request, string $window): array
    {
        $from = $request->query('from');
        $to = $request->query('to');

        if (is_numeric($from) && is_numeric($to)) {
            $from = (int) $from;
            $to = (int) $to;
            $span = $to - $from;

            if ($span >= 60 && $span <= 7 * 86400 && $to <= time() + 300) {
                return [$span, $to];
            }
        }

        return [$this->seconds($window), null];
    }

    private function section(Request $request): string
    {
        $section = (string) $request->query('section', 'overview');

        return isset(self::SECTIONS[$section]) ? $section : 'overview';
    }

    private function window(Request $request): string
    {
        $window = (string) $request->query('window', '1h');

        return isset(self::WINDOWS[$window]) ? $window : '1h';
    }

    private function seconds(string $window): int
    {
        if (preg_match('/^(\d+)([mh])$/', $window, $m) !== 1) {
            return 3600;
        }

        return (int) $m[1] * ($m[2] === 'h' ? 3600 : 60);
    }
}
