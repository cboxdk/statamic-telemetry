<?php

declare(strict_types=1);

/*
 * Regenerates the bundled Statamic Grafana dashboard
 * (dashboards/telemetry-statamic.json).
 *
 * Run after changing panels:  php resources/grafana/generate.php
 *
 * One dashboard joining cboxdk/laravel-telemetry's Nightwatch-style suite:
 * it carries the same "telemetry" tag, so it appears as a tab in every
 * suite dashboard (and they in it).
 *
 * Visual language:
 *   green = healthy/hit · orange = warn/miss/invalidate · red = error/flush
 *   smooth gradient lines, soft-zero axes, shared crosshair, section rows.
 */

final class DashboardBuilder
{
    private const PROM = ['type' => 'prometheus', 'uid' => 'prometheus'];

    private const TEMPO = ['type' => 'tempo', 'uid' => 'tempo'];

    private const LOKI = ['type' => 'loki', 'uid' => 'loki'];

    private const REQ = 'http_server_request_duration_milliseconds';

    private const SC = 'statamic_static_cache_operations_total';

    private const SVC = 'service_name=~"$service"';

    private const OUTCOME_COLORS = [
        'hit' => 'green', 'miss' => 'orange', 'write' => 'blue',
        'invalidate' => 'orange', 'flush' => 'red',
    ];

    private int $id = 0;

    private function nid(): int
    {
        return ++$this->id;
    }

    /**
     * @param  array<string, mixed>  $opts  legend, instant, fmt
     * @return array<string, mixed>
     */
    private function target(string $expr, string $legend = '__auto', bool $instant = false, ?string $fmt = null): array
    {
        $t = ['refId' => 'Q'.$this->nid(), 'expr' => $expr, 'legendFormat' => $legend, 'datasource' => self::PROM];

        if ($instant) {
            $t['instant'] = true;
            $t['range'] = false;
        }

        if ($fmt !== null) {
            $t['format'] = $fmt;
        }

        return $t;
    }

    /**
     * Fixed semantic colors per series (by legend name or regex).
     *
     * @param  array<string, string>  $mapping
     * @return list<array<string, mixed>>
     */
    private function colorOver(array $mapping, bool $regex = false): array
    {
        $out = [];

        foreach ($mapping as $key => $color) {
            $out[] = [
                'matcher' => ['id' => $regex ? 'byRegexp' : 'byName', 'options' => $key],
                'properties' => [['id' => 'color', 'value' => ['mode' => 'fixed', 'fixedColor' => $color]]],
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return array<string, mixed>
     */
    private function okAt(array $steps): array
    {
        return ['mode' => 'absolute', 'steps' => array_merge([['color' => 'green', 'value' => null]], $steps)];
    }

    /**
     * @return array<string, mixed>
     */
    private function warnAt(float|int $value, string $color = 'red'): array
    {
        return $this->okAt([['color' => $color, 'value' => $value]]);
    }

    /**
     * @param  array<string, mixed>|null  $thresholds
     * @return array<string, mixed>
     */
    private function stat(string $title, string $expr, int $x, int $y, int $w = 4, string $unit = 'short', ?array $thresholds = null, bool $bg = false, int $decimals = 1, ?string $description = null, bool $zero = false): array
    {
        if ($zero) {
            $expr = '('.$expr.') or vector(0)';
        }

        $panel = [
            'id' => $this->nid(), 'type' => 'stat', 'title' => $title, 'datasource' => self::PROM,
            'gridPos' => ['h' => 4, 'w' => $w, 'x' => $x, 'y' => $y],
            'targets' => [$this->target($expr)],
            'fieldConfig' => ['defaults' => [
                'unit' => $unit, 'decimals' => $decimals,
                'thresholds' => $thresholds ?? ['mode' => 'absolute', 'steps' => [['color' => 'text', 'value' => null]]],
            ], 'overrides' => []],
            'options' => [
                'reduceOptions' => ['calcs' => ['lastNotNull']],
                'colorMode' => $bg ? 'background' : 'value',
                'graphMode' => 'area', 'justifyMode' => 'auto', 'textMode' => 'auto',
            ],
        ];

        if ($description !== null) {
            $panel['description'] = $description;
        }

        return $panel;
    }

    /**
     * @param  list<array<string, mixed>>  $targets
     * @param  array<string, string>|null  $colors
     * @param  array<string, string>|null  $regexColors
     * @return array<string, mixed>
     */
    private function timeseries(string $title, array $targets, int $x, int $y, int $w = 12, int $h = 8, string $unit = 'short', bool $stacked = false, ?array $colors = null, ?array $regexColors = null, string $legend = 'list', ?float $thresholdLine = null, ?string $description = null): array
    {
        $overrides = [];

        if ($colors) {
            $overrides = array_merge($overrides, $this->colorOver($colors));
        }

        if ($regexColors) {
            $overrides = array_merge($overrides, $this->colorOver($regexColors, true));
        }

        $defaults = [
            'unit' => $unit, 'min' => 0,
            'custom' => [
                'drawStyle' => 'line', 'lineInterpolation' => 'smooth', 'lineWidth' => 2,
                'fillOpacity' => 18, 'gradientMode' => 'opacity', 'showPoints' => 'never',
                'spanNulls' => true, 'axisSoftMin' => 0,
                'stacking' => ['mode' => $stacked ? 'normal' : 'none'],
            ],
        ];

        if ($thresholdLine !== null) {
            $defaults['custom']['thresholdsStyle'] = ['mode' => 'dashed'];
            $defaults['thresholds'] = $this->warnAt($thresholdLine);
        }

        $panel = [
            'id' => $this->nid(), 'type' => 'timeseries', 'title' => $title, 'datasource' => self::PROM,
            'gridPos' => ['h' => $h, 'w' => $w, 'x' => $x, 'y' => $y], 'targets' => $targets,
            'fieldConfig' => ['defaults' => $defaults, 'overrides' => $overrides],
            'options' => [
                'legend' => $legend === 'table'
                    ? ['displayMode' => 'table', 'placement' => 'bottom', 'calcs' => ['mean', 'max']]
                    : ['displayMode' => 'list', 'placement' => 'bottom'],
                'tooltip' => ['mode' => 'multi', 'sort' => 'desc'],
            ],
        ];

        if ($description !== null) {
            $panel['description'] = $description;
        }

        return $panel;
    }

    /**
     * @return array<string, mixed>
     */
    private function table(string $title, string $expr, int $x, int $y, int $w = 12, int $h = 8, string $unit = 'short', ?string $description = null, int $decimals = 0): array
    {
        $panel = [
            'id' => $this->nid(), 'type' => 'table', 'title' => $title, 'datasource' => self::PROM,
            'gridPos' => ['h' => $h, 'w' => $w, 'x' => $x, 'y' => $y],
            'targets' => [$this->target($expr, instant: true, fmt: 'table')],
            'transformations' => [['id' => 'organize', 'options' => ['excludeByName' => ['Time' => true]]]],
            'fieldConfig' => ['defaults' => ['unit' => $unit, 'decimals' => $decimals,
                'custom' => ['align' => 'auto', 'filterable' => false]], 'overrides' => []],
            'options' => ['sortBy' => [['displayName' => 'Value', 'desc' => true]],
                'cellHeight' => 'md', 'footer' => ['show' => false]],
        ];

        if ($description !== null) {
            $panel['description'] = $description;
        }

        return $panel;
    }

    /**
     * @return array<string, mixed>
     */
    private function traces(string $title, string $query, int $x, int $y, int $w = 24, int $h = 9, ?string $description = null): array
    {
        $panel = [
            'id' => $this->nid(), 'type' => 'table', 'title' => $title, 'datasource' => self::TEMPO,
            'gridPos' => ['h' => $h, 'w' => $w, 'x' => $x, 'y' => $y],
            'targets' => [['refId' => 'A', 'datasource' => self::TEMPO, 'queryType' => 'traceql',
                'query' => $query, 'limit' => 20, 'spss' => 3, 'tableType' => 'traces']],
            'fieldConfig' => ['defaults' => ['custom' => ['filterable' => false]], 'overrides' => []],
            'options' => ['cellHeight' => 'sm'],
        ];

        if ($description !== null) {
            $panel['description'] = $description;
        }

        return $panel;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $title, int $y): array
    {
        return ['id' => $this->nid(), 'type' => 'row', 'title' => $title, 'collapsed' => false,
            'gridPos' => ['h' => 1, 'w' => 24, 'x' => 0, 'y' => $y], 'panels' => []];
    }

    /**
     * @return array<string, mixed>
     */
    private function qvar(string $name, string $metric, string $label): array
    {
        return ['name' => $name, 'type' => 'query', 'datasource' => self::PROM,
            'query' => ['query' => "label_values({$metric}, {$label})", 'refId' => $name],
            'includeAll' => true, 'allValue' => '.*', 'multi' => false, 'refresh' => 2,
            'current' => ['text' => 'All', 'value' => '$__all']];
    }

    /**
     * @param  list<array<string, mixed>>  $panels
     * @return array<string, mixed>
     */
    private function dashboard(string $uid, string $title, array $panels): array
    {
        return [
            'uid' => $uid, 'title' => $title, 'tags' => ['telemetry', 'cboxdk', 'statamic'],
            'timezone' => 'browser', 'schemaVersion' => 39, 'refresh' => '30s',
            'graphTooltip' => 1, // shared crosshair across panels
            'time' => ['from' => 'now-1h', 'to' => 'now'],
            // Annotation lines on every panel — a latency spike maps to its
            // cause. Deploys (from the core telemetry:deploy marker) and
            // Statamic cache purges (stache/static/glide clears), which
            // explain the slow renders that follow them.
            'annotations' => ['list' => [
                ['name' => 'Deploys', 'datasource' => self::LOKI, 'enable' => true, 'hide' => false,
                    'iconColor' => 'purple',
                    'expr' => '{service_name=~"$service"} |= `app.deployment`',
                    'titleFormat' => 'Deploy'],
                ['name' => 'Cache purges', 'datasource' => self::LOKI, 'enable' => true, 'hide' => false,
                    'iconColor' => 'orange',
                    'expr' => '{service_name=~"$service"} |= `statamic.cache.purge`',
                    'titleFormat' => 'Cache purge'],
            ]],
            'templating' => ['list' => [$this->qvar('service', self::REQ.'_count', 'service_name')]],
            // Join the core suite's Nightwatch nav: every telemetry-tagged
            // dashboard as a tab.
            'links' => [['type' => 'dashboards', 'tags' => ['telemetry'], 'asDropdown' => false,
                'includeVars' => true, 'keepTime' => true, 'title' => '']],
            'panels' => $panels, 'editable' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $SC = self::SC;
        $SVC = self::SVC;
        $REQ = self::REQ;

        return $this->dashboard('cbox-tel-statamic', 'Statamic', [
            $this->row('Static cache', 0),
            $this->stat('Hit ratio',
                '100 * sum(rate('.$SC.'{'.$SVC.',operation="hit"}[10m]))'
                .' / sum(rate('.$SC.'{'.$SVC.',operation=~"hit|miss"}[10m]))',
                0, 1, unit: 'percent', decimals: 0,
                thresholds: $this->okAt([['color' => 'orange', 'value' => 0], ['color' => 'green', 'value' => 70]]),
                description: 'Static cache hits vs misses over 10m. Full-measure hits served by the web server never reach PHP and are not counted.'),
            $this->stat('Hits / min', 'sum(rate('.$SC.'{'.$SVC.',operation="hit"}[5m])) * 60', 4, 1, decimals: 0, zero: true),
            $this->stat('Writes / min', 'sum(rate('.$SC.'{'.$SVC.',operation="write"}[5m])) * 60', 8, 1, decimals: 0, zero: true),
            $this->stat('Invalidations / h', 'sum(increase('.$SC.'{'.$SVC.',operation="invalidate"}[1h]))', 12, 1, decimals: 0, zero: true,
                thresholds: $this->warnAt(100, 'orange')),
            $this->stat('Flushes (24h)', 'sum(increase('.$SC.'{'.$SVC.',operation="flush"}[24h]))', 16, 1, decimals: 0, zero: true,
                thresholds: $this->warnAt(1, 'orange'),
                description: 'A full flush empties the static cache — every page re-renders. More than an occasional one is worth investigating.'),
            $this->stat('Stache warms (24h)', 'sum(increase(statamic_stache_warms_total{'.$SVC.'}[24h]))', 20, 1, decimals: 0, zero: true),
            $this->timeseries('Static cache: hits, misses, writes', [
                $this->target('sum(rate('.$SC.'{'.$SVC.',operation="hit"}[$__rate_interval])) * 60', 'hit'),
                $this->target('sum(rate('.$SC.'{'.$SVC.',operation="miss"}[$__rate_interval])) * 60', 'miss'),
                $this->target('sum(rate('.$SC.'{'.$SVC.',operation="write"}[$__rate_interval])) * 60', 'write'),
            ], 0, 5, unit: 'opm', colors: self::OUTCOME_COLORS),
            $this->timeseries('Static cache: invalidations & flushes', [
                $this->target('sum(rate('.$SC.'{'.$SVC.',operation="invalidate"}[$__rate_interval])) * 60', 'invalidate'),
                $this->target('sum(rate('.$SC.'{'.$SVC.',operation="flush"}[$__rate_interval])) * 60', 'flush'),
            ], 12, 5, unit: 'opm', colors: self::OUTCOME_COLORS,
                description: 'Spikes here explain miss/write spikes on the left — saves fan out invalidations by rule.'),

            $this->row('Frontend latency by content route', 13),
            $this->timeseries('p95 request duration by content route', [
                $this->target('histogram_quantile(0.95, sum by (le, http_route) '
                    .'(rate('.$REQ.'_bucket{'.$SVC.',http_route=~"(entry|term|taxonomy):.*"}[$__rate_interval])))', '{{http_route}}'),
            ], 0, 14, unit: 'ms', legend: 'table',
                description: 'With instrument.content on, the addon overrides http.route with the logical content route (entry:{collection}.{blueprint} / term:{taxonomy} / taxonomy:{handle}), so latency breaks down per content type instead of collapsing into the /{segments?} catch-all. The raw pattern is kept as http.route.template.'),
            $this->timeseries('Request rate by content route', [
                $this->target('sum by (http_route) (rate('.$REQ.'_count{'.$SVC.',http_route=~"(entry|term|taxonomy):.*"}[$__rate_interval])) * 60',
                    '{{http_route}}'),
            ], 12, 14, unit: 'reqpm', legend: 'table'),

            $this->row('Stache', 22),
            $this->timeseries('Stache cache traffic by key group', [
                $this->target('sum by (key_group) (rate(cache_operations_total{'.$SVC.',key_group=~"stache.*"}[$__rate_interval])) * 60',
                    '{{key_group}}'),
            ], 0, 23, unit: 'opm',
                description: "Requires the core cache instrumentation (telemetry.instrument.cache). Key groups come from this addon's classifier: stache.index, stache.item, stache.meta."),
            $this->timeseries('Stache warm duration', [
                $this->target('histogram_quantile(0.95, sum by (le) (rate(statamic_stache_warm_duration_milliseconds_bucket{'.$SVC.'}[$__rate_interval])))', 'p95'),
                $this->target('sum(rate(statamic_stache_warm_duration_milliseconds_sum{'.$SVC.'}[$__rate_interval]))'
                    .' / sum(rate(statamic_stache_warm_duration_milliseconds_count{'.$SVC.'}[$__rate_interval]))', 'avg'),
            ], 12, 23, unit: 'ms', colors: ['p95' => 'orange', 'avg' => 'green'],
                description: 'Full warms only (stache:warm / warm after clear). A growing warm time tracks content volume.'),

            $this->row('Content & editors', 31),
            $this->timeseries('Content changes', [
                $this->target('sum by (type, action) (rate(statamic_content_changes_total{'.$SVC.'}[$__rate_interval])) * 60',
                    '{{type}} {{action}}'),
            ], 0, 32, w: 6, unit: 'opm', stacked: true, legend: 'table',
                description: 'type/action from the content event. Entry actions are the publish status at save (published/draft/scheduled/expired) — the publish-state mix of editing, not transitions.'),
            $this->timeseries('Form submissions', [
                $this->target('sum by (form) (rate(statamic_forms_submissions_total{'.$SVC.'}[$__rate_interval])) * 60', '{{form}}'),
            ], 6, 32, w: 6, unit: 'opm'),
            $this->timeseries('Glide generations', [
                $this->target('sum by (preset) (rate(statamic_glide_generations_total{'.$SVC.'}[$__rate_interval])) * 60', '{{preset}}'),
            ], 12, 32, w: 6, unit: 'opm',
                description: "Sustained generation traffic means the Glide cache is being missed — presets bound the label; ad-hoc params group under 'custom'."),
            $this->timeseries('Search index updates', [
                $this->target('sum by (index) (rate(statamic_search_index_updates_total{'.$SVC.'}[$__rate_interval])) * 60', '{{index}}'),
            ], 18, 32, w: 6, unit: 'opm'),
            $this->timeseries('Entry saves by publish status', [
                $this->target('sum by (action) (rate(statamic_content_changes_total{'.$SVC.',type="entry"}[$__rate_interval])) * 60',
                    '{{action}}'),
            ], 0, 40, w: 8, h: 6, unit: 'opm', legend: 'table',
                colors: ['published' => 'green', 'draft' => 'yellow', 'scheduled' => 'blue', 'expired' => 'orange', 'deleted' => 'red'],
                description: 'Editorial activity split by the entry\'s publish status at save time. published = a live entry was edited/created; draft = work in progress.'),
            $this->timeseries('Statamic auth & security events', [
                $this->target('sum by (event) (rate(statamic_auth_events_total{'.$SVC.'}[$__rate_interval])) * 60', '{{event}}'),
            ], 8, 40, w: 8, h: 6, unit: 'opm', legend: 'table',
                regexColors: ['.*failed.*' => 'red', '.*impersonation.*' => 'orange'],
                description: 'Statamic-specific: impersonation and the 2FA lifecycle (a failed spike is a brute-force signal). User identity lives on the traces, not the metric.'),
            $this->timeseries('Logins & failures', [
                $this->target('sum by (event) (rate(auth_events_total{'.$SVC.',event=~"login|logout|failed|lockout"}[$__rate_interval])) * 60', '{{event}}'),
            ], 16, 40, w: 8, h: 6, unit: 'opm', legend: 'table',
                colors: ['failed' => 'red', 'lockout' => 'red', 'login' => 'green', 'logout' => 'blue'],
                description: "Laravel auth lifecycle from the base package's auth.events{event,guard} — login/logout volume and, critically, failed/lockout as the credential-attack signal."),

            $this->row('Inventory (opt-in gauges)', 46),
            $this->table('Entries by collection',
                'sum by (collection) (statamic_entries_count{'.$SVC.'})',
                0, 47, w: 8,
                description: 'Requires statamic-telemetry.gauges.enabled — evaluated at scrape time.'),
            $this->table('Assets by container',
                'sum by (container) (statamic_assets_count{'.$SVC.'})',
                8, 47, w: 8),
            $this->stat('Users', 'sum(statamic_users_count{'.$SVC.'})', 16, 47, w: 8, decimals: 0),

            $this->row('Traces', 51),
            $this->traces('Recent content requests',
                '{resource.service.name=~"$service" && name=~"GET (entry|term):.*"}',
                0, 52, h: 9,
                description: 'Root spans named by this addon — entry:{collection}.{blueprint} / term:{taxonomy}.'),
            $this->traces('Slow uncached pages',
                '{resource.service.name=~"$service" && span.statamic.static_cache="miss" && duration > 500ms}',
                0, 61, h: 9,
                description: 'Static cache misses that took over 500ms to render — the pages that hurt when the cache is cold.'),
        ]);
    }
}

$out = __DIR__.'/dashboards';

if (! is_dir($out)) {
    mkdir($out, 0755, true);
}

$json = json_encode(
    (new DashboardBuilder)->build(),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

file_put_contents($out.'/telemetry-statamic.json', $json."\n");

echo "wrote dashboards/telemetry-statamic.json\n";
