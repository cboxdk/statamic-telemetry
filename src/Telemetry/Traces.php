<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Telemetry;

use Cbox\StatamicTelemetry\Audit\Backend;
use Throwable;

/**
 * Reads request traces back from the telemetry backend's Tempo API (telemetryd
 * serves it on the same port as Loki). Guarded like the audit reader — a
 * missing or unreachable backend returns empty, never throws into the CP.
 */
final class Traces
{
    public function __construct(private readonly Backend $backend) {}

    /**
     * Recent traces in the window, newest first.
     *
     * @return list<TraceSummary>
     */
    public function search(int $windowSeconds, int $limit = 200): array
    {
        if (! $this->backend->configured()) {
            return [];
        }

        $end = time();
        $start = $end - $windowSeconds;

        try {
            $response = $this->backend->request()->get($this->backend->endpoint('api/search'), [
                'start' => $start,
                'end' => $end,
                'limit' => $limit,
            ]);
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $traces = $response->json('traces');

        if (! is_array($traces)) {
            return [];
        }

        $rows = [];

        foreach ($traces as $trace) {
            if (! is_array($trace) || ! is_string($trace['traceID'] ?? null)) {
                continue;
            }

            $rows[] = new TraceSummary(
                id: $trace['traceID'],
                name: is_string($trace['rootTraceName'] ?? null) ? $trace['rootTraceName'] : '(unnamed)',
                service: is_string($trace['rootServiceName'] ?? null) ? $trace['rootServiceName'] : null,
                durationMs: (float) ($trace['durationMs'] ?? 0),
                timestampMs: (int) ((int) ($trace['startTimeUnixNano'] ?? 0) / 1_000_000),
            );
        }

        usort($rows, static fn (TraceSummary $a, TraceSummary $b): int => $b->timestampMs <=> $a->timestampMs);

        return $rows;
    }

    /**
     * One trace's spans for a waterfall, ordered by start, with nesting depth.
     *
     * @return list<TraceSpan>
     */
    public function trace(string $id): array
    {
        if (! $this->backend->configured()) {
            return [];
        }

        try {
            $response = $this->backend->request()->get($this->backend->endpoint('api/traces/'.$id));
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $batches = $response->json('batches');

        if (! is_array($batches)) {
            return [];
        }

        // First pass: collect raw spans and the parent map.
        $raw = [];
        foreach ($batches as $batch) {
            foreach ((is_array($batch['scopeSpans'] ?? null) ? $batch['scopeSpans'] : []) as $scope) {
                foreach ((is_array($scope['spans'] ?? null) ? $scope['spans'] : []) as $span) {
                    if (! is_array($span)) {
                        continue;
                    }

                    $start = (int) ($span['startTimeUnixNano'] ?? 0);
                    $endNano = (int) ($span['endTimeUnixNano'] ?? 0);

                    $raw[] = [
                        'id' => is_string($span['spanId'] ?? null) ? $span['spanId'] : '',
                        'parent' => is_string($span['parentSpanId'] ?? null) ? $span['parentSpanId'] : '',
                        'name' => is_string($span['name'] ?? null) ? $span['name'] : '(span)',
                        'start' => $start,
                        'duration' => $endNano > $start ? ($endNano - $start) / 1_000_000 : 0.0,
                    ];
                }
            }
        }

        $byId = [];
        foreach ($raw as $span) {
            if ($span['id'] !== '') {
                $byId[$span['id']] = $span;
            }
        }

        usort($raw, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        $spans = [];
        foreach ($raw as $span) {
            $spans[] = new TraceSpan(
                name: $span['name'],
                startNano: $span['start'],
                durationMs: $span['duration'],
                depth: $this->depth($span, $byId),
            );
        }

        return $spans;
    }

    /**
     * How deep a span sits under its ancestors (bounded, cycle-safe).
     *
     * @param  array<string, mixed>  $span
     * @param  array<string, array<string, mixed>>  $byId
     */
    private function depth(array $span, array $byId): int
    {
        $depth = 0;
        $parent = is_string($span['parent'] ?? null) ? $span['parent'] : '';

        while ($parent !== '' && isset($byId[$parent]) && $depth < 20) {
            $depth++;
            $next = $byId[$parent];
            $parent = is_string($next['parent'] ?? null) ? $next['parent'] : '';
        }

        return $depth;
    }
}
