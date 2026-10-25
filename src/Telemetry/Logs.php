<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Telemetry;

use Cbox\StatamicTelemetry\Audit\Backend;
use Throwable;

/**
 * Reads log lines back from the telemetry backend's Loki API — the general
 * log/error stream for the Explore page and the per-trace lines for the trace
 * drawer, as opposed to the audit-specific reader. Guarded; a missing or
 * unreachable backend returns an empty list.
 */
final class Logs
{
    public function __construct(private readonly Backend $backend) {}

    /**
     * Recent log lines in the window, newest first. A non-empty $level filters
     * by the Loki `level` stream label (e.g. "error"); $search is a line
     * substring filter.
     *
     * @return list<array{timestamp: int, level: string, line: string, labels: array<string, string>, traceId: ?string}>
     */
    public function query(int $windowSeconds, string $level = '', string $search = '', int $limit = 200, ?int $endsAt = null): array
    {
        $query = $this->selector($level);

        if ($search !== '') {
            $query .= ' |= "'.addslashes($search).'"';
        }

        return $this->fetch($query, $windowSeconds, $limit, $endsAt);
    }

    /**
     * The log lines emitted during one trace, matched on the `trace_id`
     * structured-metadata label laravel-telemetry stamps on every record.
     *
     * @return list<array{timestamp: int, level: string, line: string, labels: array<string, string>, traceId: ?string}>
     */
    public function forTrace(string $traceId, int $windowSeconds = 86400, int $limit = 200): array
    {
        return $this->fetch($this->selector('').' | trace_id="'.addslashes($traceId).'"', $windowSeconds, $limit, null);
    }

    /**
     * @return list<array{timestamp: int, level: string, line: string, labels: array<string, string>, traceId: ?string}>
     */
    private function fetch(string $query, int $windowSeconds, int $limit, ?int $endsAt): array
    {
        if (! $this->backend->configured()) {
            return [];
        }

        $end = $endsAt ?? time();
        $start = $end - $windowSeconds;

        try {
            $response = $this->backend->request()->get($this->backend->endpoint('loki/api/v1/query_range'), [
                'query' => $query,
                'start' => $start * 1_000_000_000,
                'end' => $end * 1_000_000_000,
                'limit' => $limit,
                'direction' => 'backward',
            ]);
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $json = $response->json();

        if (! is_array($json) || ($json['status'] ?? null) !== 'success') {
            return [];
        }

        return $this->parse($json);
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<array{timestamp: int, level: string, line: string, labels: array<string, string>, traceId: ?string}>
     */
    private function parse(array $json): array
    {
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $result = is_array($data['result'] ?? null) ? $data['result'] : [];

        $rows = [];

        foreach ($result as $stream) {
            if (! is_array($stream)) {
                continue;
            }

            $labels = $this->stringMap($stream['stream'] ?? []);

            foreach ((is_array($stream['values'] ?? null) ? $stream['values'] : []) as $value) {
                if (! is_array($value) || count($value) < 2) {
                    continue;
                }

                $meta = isset($value[2]) && is_array($value[2]) ? $this->stringMap($value[2]) : [];
                $all = $meta === [] ? $labels : [...$labels, ...$meta];

                $rows[] = [
                    'timestamp' => intdiv((int) $value[0], 1_000_000),
                    'level' => $all['level'] ?? $all['detected_level'] ?? 'info',
                    'line' => (string) $value[1],
                    'labels' => $all,
                    'traceId' => $all['trace_id'] ?? $all['traceId'] ?? null,
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);

        return $rows;
    }

    private function selector(string $level): string
    {
        $label = (string) config('statamic-telemetry.cp.audit.service_label', 'service_name');
        $service = (string) (config('statamic-telemetry.cp.audit.service') ?? config('telemetry.service.name', 'laravel'));

        $matchers = [$label.'="'.addslashes($service).'"'];

        if ($level !== '') {
            $matchers[] = 'level="'.addslashes($level).'"';
        }

        return '{'.implode(', ', $matchers).'}';
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $map = [];
        foreach ($raw as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $map[$key] = $value;
            }
        }

        return $map;
    }
}
