<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Http\Controllers\Cp;

use Cbox\StatamicTelemetry\Telemetry\Deployments;
use Cbox\StatamicTelemetry\Telemetry\LatencyHeatmap;
use Cbox\StatamicTelemetry\Telemetry\Logs;
use Cbox\StatamicTelemetry\Telemetry\TelemetryMetrics;
use Cbox\StatamicTelemetry\Telemetry\Traces;
use Cbox\StatamicTelemetry\Telemetry\TraceSummary;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Statamic\Http\Controllers\CP\CpController;

/**
 * The native "Explore" CP page — a two-pane list + detail over the backend's
 * signals: requests and traces (Tempo), logs and errors (Loki). For requests
 * the page carries a stat header, a facet panel (status / method / route, with
 * counts, from metrics) and a throughput chart; clicking a facet narrows the
 * list through a TraceQL selector on the matching span attribute. The list and
 * detail are Inertia props the client reloads to filter and live-tail. Thin —
 * {@see Traces}/{@see Logs}/{@see TelemetryMetrics} do the reads.
 */
class ExploreController extends CpController
{
    /** The signals (tabs). Value → label. */
    public const SIGNALS = [
        'requests' => 'Requests',
        'traces' => 'Traces',
        'logs' => 'Logs',
        'errors' => 'Errors',
    ];

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

    /** Facet key → the span attribute its value filters on, and whether it is quoted. */
    private const FACETS = [
        'status' => ['attr' => 'span.http.response.status_code', 'quoted' => false],
        'method' => ['attr' => 'span.http.request.method', 'quoted' => true],
        'route' => ['attr' => 'span.http.route', 'quoted' => true],
    ];

    public function __invoke(Request $request, Traces $traces, Logs $logs, TelemetryMetrics $metrics, Deployments $deployments, LatencyHeatmap $heatmap): Response
    {
        $signal = $this->signal($request);
        $window = $this->window($request);
        [$seconds, $endsAt] = $this->range($request, $window);
        $search = trim((string) $request->query('q', ''));
        $id = is_string($request->query('id')) ? (string) $request->query('id') : null;
        $filters = $this->filters($request);
        $isTrace = $signal === 'requests' || $signal === 'traces';

        return Inertia::render('statamic-telemetry::Explore', [
            'signal' => $signal,
            'signals' => self::SIGNALS,
            'window' => $window,
            'windows' => self::WINDOWS,
            'from' => $endsAt !== null ? $endsAt - $seconds : null,
            'to' => $endsAt,
            'dashboardUrl' => cp_route('telemetry.dashboard'),
            'exploreUrl' => cp_route('telemetry.explore'),
            'q' => $search,
            'filters' => $filters,
            'selectedId' => $id,
            'items' => fn () => $this->items($signal, $seconds, $search, $filters, $traces, $logs, $endsAt),
            'detail' => fn () => $id !== null ? $this->detail($signal, $id, $traces) : null,
            'summary' => $isTrace ? fn () => $metrics->summary($seconds, $endsAt) : null,
            'facets' => $isTrace ? fn () => $metrics->facets($seconds, $endsAt) : null,
            'throughput' => $isTrace ? fn () => $metrics->throughput($seconds, $endsAt) : null,
            'heatmap' => $isTrace ? fn () => $heatmap->build($seconds, $this->traceql($filters), $endsAt) : null,
            'deployments' => $isTrace ? fn () => $deployments->window($seconds, 50, $endsAt) : [],
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

    /**
     * @param  array<string, string>  $filters
     * @return list<array<string, mixed>>
     */
    private function items(string $signal, int $seconds, string $search, array $filters, Traces $traces, Logs $logs, ?int $endsAt = null): array
    {
        if ($signal === 'logs' || $signal === 'errors') {
            $out = [];
            foreach ($logs->query($seconds, $signal === 'errors' ? 'error' : '', $search, 200, $endsAt) as $i => $r) {
                $out[] = ['id' => 'l'.$i] + $r;
            }

            return $out;
        }

        $list = $traces->search($seconds, 300, $this->traceql($filters), $endsAt);

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $list = array_values(array_filter($list, static fn (TraceSummary $t): bool => str_contains(mb_strtolower($t->name), $needle)));
        }

        return array_map(static fn (TraceSummary $t): array => [
            'id' => $t->id,
            'name' => $t->name,
            'service' => $t->service,
            'durationMs' => $t->durationMs,
            'timestamp' => $t->timestampMs,
        ], array_slice($list, 0, 300));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function detail(string $signal, string $id, Traces $traces): ?array
    {
        if ($signal === 'requests' || $signal === 'traces') {
            return ['spans' => $traces->trace($id)];
        }

        return null;
    }

    /**
     * The active facet filters from the request — only the known keys, only
     * non-empty values.
     *
     * @return array<string, string>
     */
    private function filters(Request $request): array
    {
        $filters = [];

        foreach (array_keys(self::FACETS) as $key) {
            $value = $request->query($key);
            if (is_string($value) && $value !== '') {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    /**
     * Build a TraceQL selector from the active facets, e.g.
     * `{span.http.route="/cp" && span.http.response.status_code=500}`.
     *
     * @param  array<string, string>  $filters
     */
    private function traceql(array $filters): ?string
    {
        $terms = [];

        foreach ($filters as $key => $value) {
            $facet = self::FACETS[$key];

            if ($key === 'status' && preg_match('/^([1-5])xx$/', $value, $m) === 1) {
                // A status class (e.g. "4xx") filters the whole range.
                $lo = (int) $m[1] * 100;
                $terms[] = $facet['attr'].'>='.$lo.' && '.$facet['attr'].'<'.($lo + 100);
            } elseif ($facet['quoted']) {
                $terms[] = $facet['attr'].'="'.addslashes($value).'"';
            } else {
                $terms[] = $facet['attr'].'='.preg_replace('/[^0-9]/', '', $value);
            }
        }

        return $terms === [] ? null : '{'.implode(' && ', $terms).'}';
    }

    private function signal(Request $request): string
    {
        $signal = (string) $request->query('signal', 'requests');

        return isset(self::SIGNALS[$signal]) ? $signal : 'requests';
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
