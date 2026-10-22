<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Http\Controllers\Cp;

use Cbox\StatamicTelemetry\Telemetry\Requests;
use Cbox\StatamicTelemetry\Telemetry\TelemetryMetrics;
use Cbox\StatamicTelemetry\Telemetry\Traces;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Statamic\Http\Controllers\CP\CpController;

/**
 * The native "Telemetry" CP page — the inline dashboard that replaces the
 * link-out to laravel-telemetry-ui. Phase 1 covers the Requests overview (from
 * the backend's traces) and a single-trace waterfall; metric and log sections
 * follow. Thin — the assemblers compute everything.
 */
class TelemetryController extends CpController
{
    /** Look-back windows offered on the page. Value → label. */
    public const WINDOWS = [
        '15m' => 'Last 15 min',
        '1h' => 'Last hour',
        '6h' => 'Last 6 hours',
        '24h' => 'Last 24 hours',
    ];

    /** The page's sections (tabs). Value → label. */
    public const SECTIONS = [
        'overview' => 'Overview',
        'statamic' => 'Statamic',
    ];

    public function __invoke(Request $request, Requests $requests, Traces $traces, TelemetryMetrics $metrics): View|Response
    {
        if (is_string($trace = $request->query('trace')) && $trace !== '') {
            $data = [
                'traceId' => $trace,
                'spans' => $traces->trace($trace),
            ];

            return view('statamic-telemetry::cp.trace', $data); // @phpstan-ignore argument.type
        }

        $window = $this->window($request);
        $seconds = $this->seconds($window);
        $section = $this->section($request);

        return Inertia::render('statamic-telemetry::Telemetry', [
            'section' => $section,
            'sections' => self::SECTIONS,
            'window' => $window,
            'windows' => self::WINDOWS,
            'overview' => $section === 'overview' ? $requests->overview($seconds) : null,
            'metrics' => $metrics->overview($seconds),
        ]);
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
