<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Audit;

use Cbox\StatamicTelemetry\Support\ContentAudit;
use Throwable;

/**
 * Reads the content audit trail back from the telemetry backend.
 *
 * The audit records are written out as `statamic.content.change` events to the
 * logs backend; the store is that backend — telemetryd (single binary, Loki
 * API out) or a full LGTM stack. Both answer the Loki HTTP query API, so one
 * bounded LogQL range query over the window returns the records, and the
 * facet lists, filtering, search and pagination happen here in PHP over that
 * window — the same shape laravel-telemetry-ui's own audit panel uses, without
 * the dependency.
 *
 * Nothing here throws into the CP: a missing URL or a backend that is down,
 * slow or speaking the wrong protocol comes back as an {@see AuditResult} with
 * an error message and no rows, so the page always renders.
 */
final class AuditLog
{
    /** How many of the window's newest records to pull before filtering. */
    private const DEFAULT_LIMIT = 1000;

    public function __construct(private readonly Backend $backend) {}

    public function enabled(): bool
    {
        if (config('statamic-telemetry.cp.audit.enabled') === false) {
            return false;
        }

        return $this->backend->configured();
    }

    public function query(AuditQuery $query, int $perPage = 50): AuditResult
    {
        [$rows, $capped, $error] = $this->fetch($query->windowSeconds());

        if ($error !== null) {
            return AuditResult::failed($error);
        }

        return $this->paginate($rows, $query, $perPage, $capped);
    }

    /**
     * The audit rows for a single entry (or other content item) by its id,
     * newest first — the per-entry change history for the telemetry fieldtype.
     *
     * @return list<AuditRow>
     */
    public function forEntry(string $contentId, int $windowSeconds): array
    {
        [$rows] = $this->fetch($windowSeconds);

        return array_values(array_filter($rows, static fn (AuditRow $row): bool => $row->id === $contentId));
    }

    /**
     * Fetch and parse the audit rows over the window.
     *
     * @return array{0: list<AuditRow>, 1: bool, 2: ?string} [rows, capped, error]
     */
    private function fetch(int $windowSeconds): array
    {
        if (! $this->backend->configured()) {
            return [[], false, 'No logs backend configured. Set STATAMIC_TELEMETRY_AUDIT_LOGS_URL to your telemetryd or Loki endpoint.'];
        }

        $end = time();
        $limit = (int) config('statamic-telemetry.cp.audit.limit', self::DEFAULT_LIMIT);

        try {
            $response = $this->backend->request()->get($this->backend->endpoint('loki/api/v1/query_range'), [
                'query' => $this->logql(),
                'start' => ($end - $windowSeconds) * 1_000_000_000,
                'end' => $end * 1_000_000_000,
                'limit' => $limit,
                'direction' => 'backward',
            ]);
        } catch (Throwable $exception) {
            return [[], false, 'Could not reach the logs backend: '.$exception->getMessage()];
        }

        if (! $response->successful()) {
            return [[], false, 'The logs backend returned HTTP '.$response->status().'.'];
        }

        $json = $response->json();

        if (! is_array($json) || ($json['status'] ?? null) !== 'success') {
            return [[], false, 'The URL answered, but not with a Loki log API.'];
        }

        $rows = $this->parse($json);

        return [$rows, count($rows) >= $limit, null];
    }

    /**
     * Turn a Loki streams response into rows, newest first. OTLP log-record
     * attributes arrive as per-line structured metadata (the optional third
     * tuple element), merged over the shared stream labels so a row sees a
     * flat attribute map either way.
     *
     * @param  array<string, mixed>  $json
     * @return list<AuditRow>
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
            $values = is_array($stream['values'] ?? null) ? $stream['values'] : [];

            foreach ($values as $value) {
                if (! is_array($value) || count($value) < 2) {
                    continue;
                }

                if (trim((string) $value[1]) !== ContentAudit::EVENT) {
                    continue;
                }

                $metadata = isset($value[2]) && is_array($value[2]) ? $this->stringMap($value[2]) : [];

                $rows[] = AuditRow::fromLabels(
                    (int) $value[0],
                    $metadata === [] ? $labels : [...$labels, ...$metadata],
                );
            }
        }

        usort($rows, static fn (AuditRow $a, AuditRow $b): int => $b->timestamp <=> $a->timestamp);

        return $rows;
    }

    /**
     * @param  list<AuditRow>  $rows
     */
    private function paginate(array $rows, AuditQuery $query, int $perPage, bool $capped): AuditResult
    {
        $types = $this->facet($rows, static fn (AuditRow $row): string => $row->type);
        $actions = $this->facet($rows, static fn (AuditRow $row): string => $row->action);
        $sites = $this->facet($rows, static fn (AuditRow $row): ?string => $row->site);

        $matched = array_values(array_filter($rows, static fn (AuditRow $row): bool => $query->matches($row)));

        $total = count($matched);
        $page = min($query->page, max(1, (int) ceil($total / $perPage)));
        $offset = ($page - 1) * $perPage;

        return new AuditResult(
            rows: array_slice($matched, $offset, $perPage),
            types: $types,
            actions: $actions,
            sites: $sites,
            total: $total,
            page: $page,
            perPage: $perPage,
            capped: $capped,
        );
    }

    /**
     * @param  list<AuditRow>  $rows
     * @param  callable(AuditRow): ?string  $pluck
     * @return list<string>
     */
    private function facet(array $rows, callable $pluck): array
    {
        $values = array_values(array_unique(array_filter(
            array_map($pluck, $rows),
            static fn (?string $value): bool => $value !== null && $value !== '',
        )));

        sort($values);

        return $values;
    }

    /**
     * The LogQL query: the stream selector piped with a line filter on the
     * event name. An explicit `selector` config overrides the default, which
     * is built from the service label and this app's telemetry service name.
     */
    private function logql(): string
    {
        $selector = config('statamic-telemetry.cp.audit.selector');

        if (! is_string($selector) || $selector === '') {
            $label = (string) config('statamic-telemetry.cp.audit.service_label', 'service_name');
            $service = (string) (config('statamic-telemetry.cp.audit.service') ?? config('telemetry.service.name', 'laravel'));
            $selector = '{'.$label.'="'.addslashes($service).'"}';
        }

        return $selector.' |= "'.ContentAudit::EVENT.'"';
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
