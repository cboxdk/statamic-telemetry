<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Http\Controllers\Cp;

use Cbox\StatamicTelemetry\Cp\TelemetryNav;
use Cbox\StatamicTelemetry\Telemetry\Deployments;
use Cbox\StatamicTelemetry\Telemetry\LatencyHeatmap;
use Cbox\StatamicTelemetry\Telemetry\Logs;
use Cbox\StatamicTelemetry\Telemetry\TelemetryMetrics;
use Cbox\StatamicTelemetry\Telemetry\TimeRange;
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
 * counts, from metrics) that filters the list through a TraceQL selector or
 * aggregates it with a group-by, a throughput chart and a latency heatmap.
 * The list and detail are Inertia props the client reloads to filter and
 * live-tail. Thin — {@see Traces}/{@see Logs}/{@see TelemetryMetrics} do the
 * reads, and {@see TimeRange} owns the window / custom range.
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

    /** Facet key → the span attribute its value filters on, and whether it is quoted. */
    private const FACETS = [
        'status' => ['attr' => 'span.http.response.status_code', 'quoted' => false],
        'method' => ['attr' => 'span.http.request.method', 'quoted' => true],
        'route' => ['attr' => 'span.http.route', 'quoted' => true],
    ];

    /** Facet key → the metric label it aggregates on for a group-by. */
    private const GROUP_LABELS = [
        'status' => 'http_response_status_code',
        'method' => 'http_request_method',
        'route' => 'http_route',
    ];

    public function __invoke(Request $request, Traces $traces, Logs $logs, TelemetryMetrics $metrics, Deployments $deployments, LatencyHeatmap $heatmap, TelemetryNav $nav): Response
    {
        $signal = $this->signal($request);
        $range = TimeRange::fromRequest($request);
        $seconds = $range->seconds;
        $endsAt = $range->endsAt;
        $search = trim((string) $request->query('q', ''));
        $id = is_string($request->query('id')) ? (string) $request->query('id') : null;
        $filters = $this->filters($request);
        $isTrace = $signal === 'requests' || $signal === 'traces';
        $groupBy = $request->query('groupBy');
        $groupBy = is_string($groupBy) && isset(self::GROUP_LABELS[$groupBy]) ? $groupBy : null;

        return Inertia::render('statamic-telemetry::Explore', [
            'signal' => $signal,
            'signals' => self::SIGNALS,
            'nav' => $nav->items('explore'),
            ...$range->toProps(),
            'dashboardUrl' => cp_route('telemetry.dashboard'),
            'exploreUrl' => cp_route('telemetry.explore'),
            'q' => $search,
            'filters' => $filters,
            'groupBy' => $groupBy,
            'groups' => $isTrace && $groupBy !== null ? fn () => $metrics->groupBy(self::GROUP_LABELS[$groupBy], $seconds, $endsAt) : [],
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
     * The detail for the selected item, tagged with its id so the client can
     * tell a fresh waterfall from the previous selection's.
     *
     * @return array<string, mixed>|null
     */
    private function detail(string $signal, string $id, Traces $traces): ?array
    {
        if ($signal === 'requests' || $signal === 'traces') {
            return ['id' => $id, 'spans' => $traces->trace($id)];
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
     * `{span.http.route="/cp" && span.http.response.status_code=500}`. A
     * status *class* ("4xx") filters the whole range.
     *
     * @param  array<string, string>  $filters
     */
    private function traceql(array $filters): ?string
    {
        $terms = [];

        foreach ($filters as $key => $value) {
            $facet = self::FACETS[$key];

            if ($key === 'status' && preg_match('/^([1-5])xx$/', $value, $m) === 1) {
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
}
