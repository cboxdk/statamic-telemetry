<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Telemetry;

use Cbox\StatamicTelemetry\Audit\AuditLog;
use Cbox\StatamicTelemetry\Audit\AuditQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The purpose-built Statamic pages — Static cache, Stache, Glide, Forms,
 * Content. Each is the same shape: an aggregate on top (stat tiles, a time
 * chart, a breakdown table — from the addon's Prometheus metrics) and the
 * rows underneath (every request / span / change in the window, searchable
 * and facet-filterable, each openable as its trace). A number is never the
 * end of the road: the rows are what the number counts.
 */
final class StatamicPages
{
    /** Page key → title, CP icon, one-line description. */
    public const PAGES = [
        'static-cache' => ['title' => 'Static cache', 'icon' => 'cache', 'description' => 'Every frontend request by static-cache outcome — hits, misses, writes — and the pages behind them.'],
        'stache' => ['title' => 'Stache', 'icon' => 'flash-bolt-lightning', 'description' => 'Stache warms and clears: how often the content index rebuilds, how long it takes, and which requests paid for it.'],
        'glide' => ['title' => 'Glide', 'icon' => 'folder-photos', 'description' => 'Image generations by preset, and the requests that triggered them.'],
        'forms' => ['title' => 'Forms', 'icon' => 'fieldtype-form', 'description' => 'Form submissions by form, each with the request that posted it.'],
        'content' => ['title' => 'Content', 'icon' => 'content-book-open', 'description' => 'Content changes by type and action, with the audit trail of who changed what.'],
    ];

    /** Page → the row keys it can facet on (key → label). */
    public const FACETS = [
        'static-cache' => ['cache' => 'Outcome', 'collection' => 'Collection', 'type' => 'Content type', 'site' => 'Site'],
        'stache' => ['op' => 'Operation'],
        'glide' => ['preset' => 'Preset', 'container' => 'Container'],
        'forms' => ['form' => 'Form'],
        'content' => ['type' => 'Type', 'action' => 'Action', 'site' => 'Site'],
    ];

    private const LIMIT = 300;

    public function __construct(
        private readonly TelemetryMetrics $metrics,
        private readonly Traces $traces,
        private readonly AuditLog $audit,
    ) {}

    public static function exists(string $page): bool
    {
        return isset(self::PAGES[$page]);
    }

    /**
     * @return array{key: string, title: string, icon: string, description: string}
     */
    public function meta(string $page): array
    {
        return ['key' => $page, ...self::PAGES[$page]];
    }

    /**
     * The active row filters from the request — only this page's facet keys.
     *
     * @return array<string, string>
     */
    public function filters(string $page, Request $request): array
    {
        $filters = [];

        foreach (array_keys(self::FACETS[$page] ?? []) as $key) {
            $value = $request->query($key);
            if (is_string($value) && $value !== '') {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    /**
     * The aggregate: stat tiles, a time chart and a breakdown table.
     *
     * @return array{stats: list<array<string, mixed>>, chart: array{t: list<int>, series: list<array<string, mixed>>, unit: string}, breakdown: ?array{heading: string, subheading: ?string, columns: list<array{key: string, label: string, align?: string, kind?: string}>, rows: list<array<string, mixed>>}}
     */
    public function aggregate(string $page, TimeRange $range): array
    {
        $s = $range->seconds;
        $at = $range->endsAt;

        return match ($page) {
            'static-cache' => $this->staticCache($s, $at),
            'stache' => $this->stache($s, $at),
            'glide' => $this->glide($s, $at),
            'forms' => $this->forms($s, $at),
            'content' => $this->content($s, $at),
            default => throw new \InvalidArgumentException("Unknown telemetry page [{$page}]."),
        };
    }

    /**
     * The rows: `kind` says how the client renders them — `requests` (trace
     * rows), `spans` (one span each, in its request) or `audit` (the
     * content-change trail). Facets are counted before the filters apply.
     *
     * @param  array<string, string>  $filters
     * @return array<string, mixed>
     */
    public function rows(string $page, TimeRange $range, string $q, array $filters, Request $request): array
    {
        if ($page === 'content') {
            return $this->auditRows($range, $request);
        }

        $rows = match ($page) {
            'static-cache' => array_map(static fn (TraceSummary $t): array => $t->toRow(), $this->traces->search($range->seconds, self::LIMIT, '{'.Traces::SERVER_SPANS.' && span.statamic.static_cache=~".+"}', $range->endsAt)),
            'stache' => $this->spanRows('{name=~"statamic.stache.(warm|clear)"}', $range, static fn (array $span): array => [
                'op' => str_starts_with($span['name'], 'statamic.stache.clear') ? 'clear' : 'warm',
                'label' => str_starts_with($span['name'], 'statamic.stache.clear') ? 'Stache cleared' : 'Stache warmed',
            ]),
            'glide' => $this->spanRows('{name="statamic.glide.generate"}', $range, static fn (array $span): array => [
                'preset' => $span['attributes']['statamic.glide.preset'] ?? '(none)',
                'container' => $span['attributes']['statamic.asset.container'] ?? null,
                'label' => $span['attributes']['statamic.glide.path'] ?? 'image',
            ]),
            'forms' => $this->spanRows('{name="statamic.forms.submit"}', $range, static fn (array $span): array => [
                'form' => $span['attributes']['statamic.form'] ?? '(unknown)',
                'label' => $span['attributes']['statamic.form'] ?? 'submission',
            ]),
            default => throw new \InvalidArgumentException("Unknown telemetry page [{$page}]."),
        };

        $facets = $this->facets($page, $rows);
        $total = count($rows);
        $rows = $this->filter($rows, $q, $filters);

        return [
            'kind' => $page === 'static-cache' ? 'requests' : 'spans',
            'items' => $rows,
            'facets' => $facets,
            'facetLabels' => self::FACETS[$page],
            'total' => $total,
            'capped' => $total >= self::LIMIT,
            'groups' => $page === 'static-cache' ? $this->cacheByCollection($rows) : null,
        ];
    }

    // ── Aggregates ────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function staticCache(int $s, ?int $at): array
    {
        $ops = $this->metrics->countsBy('statamic_static_cache_operations_total', 'operation', $s, $at);
        $hits = $ops['hit'] ?? 0.0;
        $misses = $ops['miss'] ?? 0.0;
        $served = $hits + $misses;

        $stats = [
            ['label' => 'Hit ratio', 'value' => $served > 0 ? round($hits / $served * 100).'%' : '—', 'tone' => $served > 0 && $hits / $served < 0.5 ? 'text-amber-600 dark:text-amber-400' : '', 'sub' => $served > 0 ? number_format($served).' served' : 'nothing served from cache'],
            ['label' => 'Hits', 'value' => number_format($hits)],
            ['label' => 'Misses', 'value' => number_format($misses)],
        ];
        foreach ($ops as $op => $count) {
            if (! in_array($op, ['hit', 'miss'], true)) {
                $stats[] = ['label' => ucfirst($op).'s', 'value' => number_format($count), 'dim' => true];
            }
        }

        $colors = ['hit' => 'var(--color-green-500)', 'miss' => 'var(--color-amber-500)', 'write' => 'var(--color-blue-500)'];

        return [
            'stats' => $stats,
            'chart' => $this->chart($this->metrics->increaseSeries('statamic_static_cache_operations_total', 'operation', $s, $at), $colors, 'bar', 'ops'),
            'breakdown' => null, // per-collection outcomes come from the rows (`groups`) — the metric has no collection label.
        ];
    }

    /** @return array<string, mixed> */
    private function stache(int $s, ?int $at): array
    {
        $warms = $this->metrics->increase('statamic_stache_warms_total', $s, $at);
        $clears = $this->metrics->increase('statamic_stache_clears_total', $s, $at);
        $p95 = $this->metrics->p95Ms('statamic_stache_warm_duration_seconds_bucket', $s, $at);

        $warmSeries = $this->metrics->increaseSeries('statamic_stache_warms_total', null, $s, $at);
        $clearSeries = $this->metrics->increaseSeries('statamic_stache_clears_total', null, $s, $at);

        return [
            'stats' => [
                ['label' => 'Warms', 'value' => number_format($warms), 'sub' => 'index rebuilds'],
                ['label' => 'Clears', 'value' => number_format($clears), 'dim' => $clears === 0.0],
                ['label' => 'Warm p95', 'value' => $p95 !== null ? $this->ms($p95) : '—', 'tone' => $p95 !== null && $p95 >= 1000 ? 'text-red-500' : ''],
            ],
            'chart' => $this->mergeCharts(['warms' => $warmSeries, 'clears' => $clearSeries], ['warms' => 'var(--color-blue-500)', 'clears' => 'var(--color-amber-500)']),
            'breakdown' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function glide(int $s, ?int $at): array
    {
        $byPreset = $this->metrics->countsBy('statamic_glide_generations_total', 'preset', $s, $at);
        $total = array_sum($byPreset);
        $clears = $this->metrics->increase('statamic_glide_cache_clears_total', $s, $at);

        return [
            'stats' => [
                ['label' => 'Generations', 'value' => number_format($total), 'sub' => 'images rendered'],
                ['label' => 'Presets', 'value' => number_format(count($byPreset)), 'dim' => true],
                ['label' => 'Cache clears', 'value' => number_format($clears), 'dim' => $clears === 0.0],
            ],
            'chart' => $this->chart($this->metrics->increaseSeries('statamic_glide_generations_total', 'preset', $s, $at), [], 'bar', 'generations'),
            'breakdown' => $this->table('By preset', null, [['key' => 'preset', 'label' => 'Preset', 'kind' => 'mono'], ['key' => 'count', 'label' => 'Generations', 'align' => 'end'], ['key' => 'share', 'label' => 'Share', 'kind' => 'share']], $this->shares($byPreset, 'preset')),
        ];
    }

    /** @return array<string, mixed> */
    private function forms(int $s, ?int $at): array
    {
        $byForm = $this->metrics->countsBy('statamic_forms_submissions_total', 'form', $s, $at);

        return [
            'stats' => [
                ['label' => 'Submissions', 'value' => number_format(array_sum($byForm))],
                ['label' => 'Forms', 'value' => number_format(count($byForm)), 'dim' => true],
            ],
            'chart' => $this->chart($this->metrics->increaseSeries('statamic_forms_submissions_total', 'form', $s, $at), [], 'bar', 'submissions'),
            'breakdown' => $this->table('By form', null, [['key' => 'form', 'label' => 'Form', 'kind' => 'mono'], ['key' => 'count', 'label' => 'Submissions', 'align' => 'end'], ['key' => 'share', 'label' => 'Share', 'kind' => 'share']], $this->shares($byForm, 'form')),
        ];
    }

    /** @return array<string, mixed> */
    private function content(int $s, ?int $at): array
    {
        $byAction = $this->metrics->countsBy('statamic_content_changes_total', 'action', $s, $at);
        $byType = $this->metrics->countsBy('statamic_content_changes_total', 'type', $s, $at);

        $stats = [['label' => 'Changes', 'value' => number_format(array_sum($byAction))]];
        foreach (array_slice($byAction, 0, 4, true) as $action => $count) {
            $stats[] = ['label' => ucfirst($action), 'value' => number_format($count), 'tone' => $action === 'deleted' ? 'text-red-500' : ''];
        }

        $colors = ['created' => 'var(--color-green-500)', 'updated' => 'var(--color-blue-500)', 'saved' => 'var(--color-blue-500)', 'deleted' => 'var(--color-red-500)', 'published' => 'var(--color-green-500)', 'unpublished' => 'var(--color-amber-500)'];

        return [
            'stats' => $stats,
            'chart' => $this->chart($this->metrics->increaseSeries('statamic_content_changes_total', 'action', $s, $at), $colors, 'bar', 'changes'),
            'breakdown' => $this->table('By content type', null, [['key' => 'type', 'label' => 'Type', 'kind' => 'mono'], ['key' => 'count', 'label' => 'Changes', 'align' => 'end'], ['key' => 'share', 'label' => 'Share', 'kind' => 'share']], $this->shares($byType, 'type')),
        ];
    }

    // ── Rows ─────────────────────────────────────────────────────────────────

    /**
     * Span rows for a page: the span, when, how long, in which request —
     * plus the page's own columns from `$fields`.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $fields
     * @return list<array<string, mixed>>
     */
    private function spanRows(string $traceql, TimeRange $range, callable $fields): array
    {
        $rows = [];

        foreach ($this->traces->spans($traceql, $range->seconds, self::LIMIT, $range->endsAt) as $i => $span) {
            $rows[] = [
                'id' => $span['traceId'].'-'.$i,
                'traceId' => $span['traceId'],
                'timestamp' => $span['timestampMs'],
                'durationMs' => $span['durationMs'],
                'span' => $span['name'],
                // The request it ran in — unless the span *is* the trace root (e.g. a generation outside a request).
                'request' => $span['rootName'] !== $span['name'] ? $span['rootName'] : null,
                'service' => $span['rootService'],
                ...$fields($span),
            ];
        }

        return $rows;
    }

    /**
     * The content-change trail from the audit log, on this page's window.
     *
     * @return array<string, mixed>
     */
    private function auditRows(TimeRange $range, Request $request): array
    {
        if (! $this->audit->enabled()) {
            return ['kind' => 'audit', 'available' => false, 'reason' => 'The audit log needs a logs backend (statamic-telemetry.cp.audit.url).'];
        }

        if (! Gate::allows('view content audit log')) {
            return ['kind' => 'audit', 'available' => false, 'reason' => 'You do not have permission to view the content audit log.'];
        }

        $base = AuditQuery::fromRequest($request);
        $query = new AuditQuery($base->type, $base->action, $base->site, $base->search, $range->window, $base->page);
        $result = $this->audit->query($query);

        $facet = static fn (array $values): array => array_map(static fn (string $v): array => ['value' => $v, 'count' => null], $values);

        return [
            'kind' => 'audit',
            'available' => true,
            'query' => $query,
            'result' => $result,
            'facets' => ['type' => $facet($result->types), 'action' => $facet($result->actions), 'site' => count($result->sites) > 1 ? $facet($result->sites) : []],
            'facetLabels' => self::FACETS['content'],
            'total' => $result->total,
            'capped' => $result->capped,
        ];
    }

    /**
     * Count each facet's values across the rows, busiest first.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, list<array{value: string, count: int}>>
     */
    private function facets(string $page, array $rows): array
    {
        $facets = [];

        foreach (array_keys(self::FACETS[$page]) as $key) {
            $counts = [];
            foreach ($rows as $row) {
                $value = $row[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    $counts[$value] = ($counts[$value] ?? 0) + 1;
                }
            }
            arsort($counts);
            $facets[$key] = array_map(
                static fn (string $value, int $count): array => ['value' => $value, 'count' => $count],
                array_keys($counts),
                array_values($counts),
            );
        }

        return $facets;
    }

    /**
     * Free-text search over every string field, and exact facet matches.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $filters
     * @return list<array<string, mixed>>
     */
    private function filter(array $rows, string $q, array $filters): array
    {
        $needle = mb_strtolower($q);

        return array_values(array_filter($rows, static function (array $row) use ($needle, $filters): bool {
            foreach ($filters as $key => $value) {
                if ((string) ($row[$key] ?? '') !== $value) {
                    return false;
                }
            }

            if ($needle === '') {
                return true;
            }

            $haystack = mb_strtolower(implode(' ', array_filter($row, static fn ($v): bool => is_string($v))));

            return str_contains($haystack, $needle);
        }));
    }

    /**
     * Static-cache outcomes per collection, from the listed requests.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{heading: string, subheading: ?string, columns: list<array<string, string>>, rows: list<array<string, mixed>>}
     */
    private function cacheByCollection(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $key = $row['collection'] ?? ($row['route'] ?? '(other)');
            $groups[$key] ??= ['collection' => $key, 'hit' => 0, 'miss' => 0, 'write' => 0, 'other' => 0, 'total' => 0];
            $outcome = $row['cache'] ?? 'other';
            $groups[$key][isset($groups[$key][$outcome]) ? $outcome : 'other']++;
            $groups[$key]['total']++;
        }

        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        $groups = array_map(static function (array $g): array {
            $served = $g['hit'] + $g['miss'];
            $g['ratio'] = $served > 0 ? round($g['hit'] / $served * 100).'%' : '—';

            return $g;
        }, $groups);

        return $this->table('By collection', 'From the requests listed below', [
            ['key' => 'collection', 'label' => 'Collection / route', 'kind' => 'mono'],
            ['key' => 'total', 'label' => 'Requests', 'align' => 'end'],
            ['key' => 'hit', 'label' => 'Hits', 'align' => 'end'],
            ['key' => 'miss', 'label' => 'Misses', 'align' => 'end'],
            ['key' => 'write', 'label' => 'Writes', 'align' => 'end'],
            ['key' => 'ratio', 'label' => 'Hit ratio', 'align' => 'end'],
        ], $groups);
    }

    // ── Shaping ──────────────────────────────────────────────────────────────

    /**
     * A chart payload from an increaseSeries result: one chart series per
     * label value, coloured when a colour is known.
     *
     * @param  array{t: list<int>, series: array<string, list<float>>}  $series
     * @param  array<string, string>  $colors
     * @return array{t: list<int>, series: list<array<string, mixed>>, unit: string, stack: bool}
     */
    private function chart(array $series, array $colors, string $type, string $name): array
    {
        $out = [];
        foreach ($series['series'] as $label => $values) {
            $out[] = ['name' => $label === 'total' ? $name : $label, 'data' => $values, 'type' => $type, 'stack' => 'a', 'color' => $colors[$label] ?? null];
        }

        return ['t' => $series['t'], 'series' => $out, 'unit' => 'count', 'stack' => true];
    }

    /**
     * Several single-series results onto one time axis (their grids match
     * when the window and step match, but never assume it).
     *
     * @param  array<string, array{t: list<int>, series: array<string, list<float>>}>  $charts
     * @param  array<string, string>  $colors
     * @return array{t: list<int>, series: list<array<string, mixed>>, unit: string, stack: bool}
     */
    private function mergeCharts(array $charts, array $colors): array
    {
        $grid = [];
        foreach ($charts as $chart) {
            foreach ($chart['t'] as $t) {
                $grid[$t] = true;
            }
        }
        $grid = array_keys($grid);
        sort($grid);

        $out = [];
        foreach ($charts as $name => $chart) {
            $byTs = array_combine($chart['t'], $chart['series']['total'] ?? array_fill(0, count($chart['t']), 0.0)) ?: [];
            $out[] = ['name' => $name, 'data' => array_map(static fn (int $t): float => $byTs[$t] ?? 0.0, $grid), 'type' => 'bar', 'stack' => 'a', 'color' => $colors[$name] ?? null];
        }

        return ['t' => $grid, 'series' => $out, 'unit' => 'count', 'stack' => true];
    }

    /**
     * @param  list<array<string, string>>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @return array{heading: string, subheading: ?string, columns: list<array<string, string>>, rows: list<array<string, mixed>>}
     */
    private function table(string $heading, ?string $subheading, array $columns, array $rows): array
    {
        return ['heading' => $heading, 'subheading' => $subheading, 'columns' => $columns, 'rows' => $rows];
    }

    /**
     * Label → count map as rows with each value's share of the total.
     *
     * @param  array<string, float>  $counts
     * @return list<array<string, mixed>>
     */
    private function shares(array $counts, string $key): array
    {
        $total = max(1.0, array_sum($counts));
        $rows = [];
        foreach ($counts as $value => $count) {
            $rows[] = [$key => (string) $value, 'count' => $count, 'share' => round($count / $total, 4)];
        }

        return $rows;
    }

    private function ms(float $ms): string
    {
        return $ms >= 1000 ? round($ms / 1000, 2).' s' : round($ms).' ms';
    }
}
