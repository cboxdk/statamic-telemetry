<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Http\Controllers\Cp;

use Cbox\StatamicTelemetry\Telemetry\Logs;
use Cbox\StatamicTelemetry\Telemetry\Traces;
use Cbox\StatamicTelemetry\Telemetry\TraceSummary;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Statamic\Http\Controllers\CP\CpController;

/**
 * The native "Explore" CP page — a two-pane list + detail over the backend's
 * signals: requests and traces (Tempo), logs and errors (Loki). The list is a
 * partial-reloadable Inertia prop so the client can filter and live-tail; the
 * detail is loaded for the selected item. Thin — {@see Traces}/{@see Logs} do
 * the reads.
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
        '15m' => 'Last 15 min',
        '1h' => 'Last hour',
        '6h' => 'Last 6 hours',
        '24h' => 'Last 24 hours',
    ];

    public function __invoke(Request $request, Traces $traces, Logs $logs): Response
    {
        $signal = $this->signal($request);
        $window = $this->window($request);
        $seconds = $this->seconds($window);
        $search = trim((string) $request->query('q', ''));
        $id = is_string($request->query('id')) ? (string) $request->query('id') : null;

        return Inertia::render('statamic-telemetry::Explore', [
            'signal' => $signal,
            'signals' => self::SIGNALS,
            'window' => $window,
            'windows' => self::WINDOWS,
            'q' => $search,
            'selectedId' => $id,
            'items' => fn () => $this->items($signal, $seconds, $search, $traces, $logs),
            'detail' => fn () => $id !== null ? $this->detail($signal, $id, $traces) : null,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(string $signal, int $seconds, string $search, Traces $traces, Logs $logs): array
    {
        if ($signal === 'logs' || $signal === 'errors') {
            $out = [];
            foreach ($logs->query($seconds, $signal === 'errors' ? 'error' : '', $search) as $i => $r) {
                $out[] = ['id' => 'l'.$i] + $r;
            }

            return $out;
        }

        $list = $traces->search($seconds, 300);

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
