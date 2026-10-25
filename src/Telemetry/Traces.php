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
    /**
     * TraceQL term for HTTP request (server) spans. The search API returns
     * the attributes of the span a query *matched*; this makes that the
     * request span, so a list row knows its path, status and Statamic
     * content. Without a selector the first span by start time is matched.
     */
    public const SERVER_SPANS = 'kind=server';

    public function __construct(private readonly Backend $backend) {}

    /**
     * Recent traces in the window, newest first. An optional TraceQL selector
     * (e.g. `{span.http.response.status_code=500}`) narrows the search to the
     * matching traces — how the Explore facets filter the list.
     *
     * @return list<TraceSummary>
     */
    public function search(int $windowSeconds, int $limit = 200, ?string $traceql = null, ?int $endsAt = null): array
    {
        $params = ['limit' => $limit];

        if (is_string($traceql) && $traceql !== '') {
            $params['q'] = $traceql;
        }

        return $this->query($params, $windowSeconds, $endsAt);
    }

    /**
     * Traces for one entry, via TraceQL on the `statamic.entry.id` attribute
     * the addon stamps on each content request's root span.
     *
     * @return list<TraceSummary>
     */
    public function byEntry(string $entryId, int $windowSeconds, int $limit = 50): array
    {
        return $this->query(['q' => '{span.statamic.entry.id="'.addslashes($entryId).'"}', 'limit' => $limit], $windowSeconds);
    }

    /**
     * The spans a TraceQL query matched, newest first — one row per span with
     * its attributes and the request (trace) it ran in. This is the row-level
     * view behind an aggregate: every Glide generation, every Stache warm,
     * every form submission, each openable as its trace.
     *
     * @return list<array{traceId: string, rootName: string, rootService: ?string, name: string, timestampMs: int, durationMs: float, attributes: array<string, string>}>
     */
    public function spans(string $traceql, int $windowSeconds, int $limit = 200, ?int $endsAt = null): array
    {
        $rows = [];

        foreach ($this->raw(['q' => $traceql, 'limit' => $limit], $windowSeconds, $endsAt) as $trace) {
            foreach ((is_array($trace['spanSets'] ?? null) ? $trace['spanSets'] : []) as $set) {
                foreach ((is_array($set) && is_array($set['spans'] ?? null) ? $set['spans'] : []) as $span) {
                    if (! is_array($span) || ! is_string($span['name'] ?? null)) {
                        continue;
                    }

                    $rows[] = [
                        'traceId' => $trace['traceID'],
                        'rootName' => is_string($trace['rootTraceName'] ?? null) ? $trace['rootTraceName'] : '(unnamed)',
                        'rootService' => is_string($trace['rootServiceName'] ?? null) ? $trace['rootServiceName'] : null,
                        'name' => $span['name'],
                        'timestampMs' => (int) ((int) ($span['startTimeUnixNano'] ?? $trace['startTimeUnixNano'] ?? 0) / 1_000_000),
                        'durationMs' => round(((int) ($span['durationNanos'] ?? 0)) / 1e6, 2),
                        'attributes' => $this->attributes($span['attributes'] ?? null, $span['status'] ?? null),
                    ];
                }
            }
        }

        usort($rows, static fn (array $a, array $b): int => $b['timestampMs'] <=> $a['timestampMs']);

        return $rows;
    }

    /**
     * The decoded traces of one search call (each with a string traceID).
     *
     * @param  array<string, scalar>  $params
     * @return list<array<string, mixed>>
     */
    private function raw(array $params, int $windowSeconds, ?int $endsAt = null): array
    {
        if (! $this->backend->configured()) {
            return [];
        }

        $end = $endsAt ?? time();

        try {
            $response = $this->backend->request()->get($this->backend->endpoint('api/search'), [
                'start' => $end - $windowSeconds,
                'end' => $end,
                ...$params,
            ]);
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $traces = $response->json('traces');

        return is_array($traces)
            ? array_values(array_filter($traces, static fn ($trace): bool => is_array($trace) && is_string($trace['traceID'] ?? null)))
            : [];
    }

    /**
     * @param  array<string, scalar>  $params
     * @return list<TraceSummary>
     */
    private function query(array $params, int $windowSeconds, ?int $endsAt = null): array
    {
        $rows = [];

        foreach ($this->raw($params, $windowSeconds, $endsAt) as $trace) {
            $rows[] = new TraceSummary(
                id: $trace['traceID'],
                name: is_string($trace['rootTraceName'] ?? null) ? $trace['rootTraceName'] : '(unnamed)',
                service: is_string($trace['rootServiceName'] ?? null) ? $trace['rootServiceName'] : null,
                durationMs: (float) ($trace['durationMs'] ?? 0),
                timestampMs: (int) ((int) ($trace['startTimeUnixNano'] ?? 0) / 1_000_000),
                attributes: $this->rootAttributes($trace),
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

        // First pass: collect raw spans (with attributes) and the parent map.
        $raw = [];
        foreach ($batches as $batch) {
            $service = $this->serviceOf($batch);

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
                        'service' => $service,
                        'kind' => $this->kindOf($span['kind'] ?? null),
                        'attributes' => $this->attributes($span['attributes'] ?? null, $span['status'] ?? null),
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
                service: $span['service'],
                kind: $span['kind'],
                attributes: $span['attributes'],
            );
        }

        return $spans;
    }

    /** The `service.name` resource attribute of a batch, if any. */
    private function serviceOf(mixed $batch): ?string
    {
        if (! is_array($batch)) {
            return null;
        }

        $attrs = $this->attributes($batch['resource']['attributes'] ?? null, null);

        return $attrs['service.name'] ?? null;
    }

    /** OTLP SpanKind enum → a short label. */
    private function kindOf(mixed $kind): ?string
    {
        return match ($kind) {
            2, 'SPAN_KIND_SERVER' => 'server',
            3, 'SPAN_KIND_CLIENT' => 'client',
            4, 'SPAN_KIND_PRODUCER' => 'producer',
            5, 'SPAN_KIND_CONSUMER' => 'consumer',
            1, 'SPAN_KIND_INTERNAL' => 'internal',
            default => null,
        };
    }

    /**
     * The search API returns the matched (root) span with its attributes in
     * `spanSets`; keep the request and Statamic ones for the list row.
     *
     * @param  array<string, mixed>  $trace
     * @return array<string, string>
     */
    private function rootAttributes(array $trace): array
    {
        $root = $trace['spanSets'][0]['spans'][0] ?? null;

        if (! is_array($root)) {
            return [];
        }

        return array_filter(
            $this->attributes($root['attributes'] ?? null, $root['status'] ?? null),
            static fn (string $key): bool => in_array($key, TraceSummary::KEEP, true) || str_starts_with($key, 'statamic.'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Flatten an OTLP attribute list into a {key: value} string map, newest
     * key wins. A non-OK status is surfaced as a synthetic `status` attribute.
     *
     * @return array<string, string>
     */
    private function attributes(mixed $attributes, mixed $status): array
    {
        $out = [];

        if (is_array($attributes)) {
            foreach ($attributes as $attr) {
                if (is_array($attr) && is_string($attr['key'] ?? null)) {
                    $out[$attr['key']] = $this->attrValue($attr['value'] ?? null);
                }
            }
        }

        $code = is_array($status) ? ($status['code'] ?? null) : null;
        if ($code === 2 || $code === 'STATUS_CODE_ERROR') {
            $message = isset($status['message']) && is_string($status['message']) ? $status['message'] : '';
            $out['status'] = $message !== '' ? 'error: '.$message : 'error';
        }

        ksort($out);

        return $out;
    }

    /** One OTLP any-value → a readable string. */
    private function attrValue(mixed $value): string
    {
        if (! is_array($value)) {
            return (string) (is_scalar($value) ? $value : '');
        }

        if (isset($value['stringValue']) && is_scalar($value['stringValue'])) {
            return (string) $value['stringValue'];
        }
        if (isset($value['intValue']) && is_scalar($value['intValue'])) {
            return (string) $value['intValue'];
        }
        if (isset($value['doubleValue']) && is_scalar($value['doubleValue'])) {
            return (string) $value['doubleValue'];
        }
        if (isset($value['boolValue'])) {
            return $value['boolValue'] ? 'true' : 'false';
        }
        if (isset($value['arrayValue']['values']) && is_array($value['arrayValue']['values'])) {
            return implode(', ', array_map(fn ($v): string => $this->attrValue($v), $value['arrayValue']['values']));
        }

        return '';
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
