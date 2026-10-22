<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Telemetry;

use Cbox\StatamicTelemetry\Audit\Backend;
use Throwable;

/**
 * Reads metrics back from the telemetry backend's Prometheus query API
 * (telemetryd serves it on the same port as Loki and Tempo). Guarded — a
 * missing or unreachable backend returns empty, never throws into the CP.
 *
 * laravel-telemetry's metrics reach the backend when the app runs with a
 * shared store (`TELEMETRY_STORE` = sqlite/redis/apcu) and `telemetry:flush`
 * ships them over OTLP; with the default per-process `array` store there is
 * nothing to read, and the panels show their empty state.
 */
final class Metrics
{
    public function __construct(private readonly Backend $backend) {}

    /**
     * An instant vector query. Returns one entry per series: its labels and
     * the scalar value.
     *
     * @return list<array{labels: array<string, string>, value: float}>
     */
    public function instant(string $query): array
    {
        $json = $this->get('api/v1/query', ['query' => $query, 'time' => time()]);

        if ($json === null || ($json['data']['resultType'] ?? null) !== 'vector') {
            return [];
        }

        $result = is_array($json['data']['result'] ?? null) ? $json['data']['result'] : [];
        $series = [];

        foreach ($result as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $value = $entry['value'][1] ?? null;

            $series[] = [
                'labels' => $this->labels($entry['metric'] ?? []),
                'value' => is_numeric($value) ? (float) $value : 0.0,
            ];
        }

        return $series;
    }

    /** The single scalar from an instant query (0.0 when there is no data). */
    public function scalar(string $query): float
    {
        return $this->instant($query)[0]['value'] ?? 0.0;
    }

    /**
     * A range query over the window. Returns one entry per series with its
     * evenly-stepped points as [unixSeconds, value].
     *
     * @return list<array{labels: array<string, string>, points: list<array{0: float, 1: float}>}>
     */
    public function range(string $query, int $windowSeconds, int $step = 60): array
    {
        $end = time();
        $json = $this->get('api/v1/query_range', [
            'query' => $query,
            'start' => $end - $windowSeconds,
            'end' => $end,
            'step' => max(15, $step),
        ]);

        if ($json === null || ($json['data']['resultType'] ?? null) !== 'matrix') {
            return [];
        }

        $result = is_array($json['data']['result'] ?? null) ? $json['data']['result'] : [];
        $series = [];

        foreach ($result as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $points = [];
            foreach ((is_array($entry['values'] ?? null) ? $entry['values'] : []) as $point) {
                if (is_array($point) && count($point) >= 2 && is_numeric($point[1])) {
                    $points[] = [(float) $point[0], (float) $point[1]];
                }
            }

            $series[] = ['labels' => $this->labels($entry['metric'] ?? []), 'points' => $points];
        }

        return $series;
    }

    /** Just the y-values of the first series, for a sparkline. @return list<float> */
    public function series(string $query, int $windowSeconds, int $step = 60): array
    {
        $range = $this->range($query, $windowSeconds, $step);

        return $range === [] ? [] : array_map(static fn (array $p): float => $p[1], $range[0]['points']);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|null
     */
    private function get(string $path, array $params): ?array
    {
        if (! $this->backend->configured()) {
            return null;
        }

        try {
            $response = $this->backend->request()->get($this->backend->endpoint($path), $params);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();

        return is_array($json) && ($json['status'] ?? null) === 'success' ? $json : null;
    }

    /**
     * @return array<string, string>
     */
    private function labels(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $labels = [];
        foreach ($raw as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $labels[$key] = $value;
            }
        }

        return $labels;
    }
}
