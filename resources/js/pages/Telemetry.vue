<template>
    <div>
        <Head title="Telemetry" />

        <Header title="Telemetry" icon="chart-monitoring-indicator">
            <button v-if="to" @click="resetRange" class="tel-chip tel-chip--blue" style="cursor: pointer" :title="`Reset to ${windows[window]}`">{{ rangeLabel }} ✕</button>
            <Button size="sm" :variant="live ? 'primary' : 'ghost'" @click="toggleLive">{{ live ? '● Live' : 'Live' }}</Button>
            <select
                :value="window"
                @change="changeWindow($event.target.value)"
                class="h-9 rounded-lg border border-gray-300 bg-white px-2 text-sm dark:border-gray-700 dark:bg-gray-800"
            >
                <option v-for="(label, value) in windows" :key="value" :value="value">{{ label }}</option>
            </select>
        </Header>

        <TelemetryTabs :active="section" :window="window" :dashboard-url="dashboardUrl" :explore-url="exploreUrl" />

        <!-- ───────────── Overview ───────────── -->
        <template v-if="section === 'overview'">
            <!-- Releases strip -->
            <div v-if="deployments.length" class="tel-releases">
                <span class="tel-label">Releases</span>
                <span v-for="d in deployments" :key="d.timestamp + d.id" class="tel-release">
                    <span class="tel-release__dot" />{{ time(d.timestamp) }} · {{ d.notes || d.id }}
                </span>
            </div>

            <!-- Headline charts -->
            <div class="tel-grid" style="grid-template-columns: repeat(auto-fit, minmax(29rem, 1fr)); margin-bottom: 1rem">
                <!-- Requests over time -->
                <div class="tel-card">
                    <div class="tel-card__head">
                        <div>
                            <span class="tel-label">Requests</span>
                            <div class="tel-metric">{{ fmt(requestCount) }}<span v-if="delta.requests != null" class="tel-delta">{{ arrow(delta.requests) }} {{ deltaPct(delta.requests) }}</span></div>
                        </div>
                        <div class="tel-chips">
                            <span class="tel-chip tel-chip--green">1/2/3XX {{ fmt(okCount) }}</span>
                            <Link class="tel-chip" :class="clientCount > 0 ? 'tel-chip--amber' : ''" :href="statusHref('4xx')" style="text-decoration: none">4XX {{ fmt(clientCount) }}</Link>
                            <Link class="tel-chip" :class="serverCount > 0 ? 'tel-chip--red' : ''" :href="statusHref('5xx')" style="text-decoration: none">5XX {{ fmt(serverCount) }}</Link>
                        </div>
                    </div>
                    <div style="margin-top: 0.75rem">
                        <TimeChart v-if="hasReqSeries" :time="reqTime" :series="reqChartSeries" :markers="chartMarkers" unit="count" :smooth="false" :height="210" brush @zoom="onZoom" />
                        <div v-else class="tel-chart-empty" style="height: 210px">No request series in this window.</div>
                    </div>
                </div>

                <!-- Duration over time -->
                <div class="tel-card">
                    <div style="display: flex; gap: 1.75rem">
                        <div>
                            <span class="tel-label">Avg latency</span>
                            <div class="tel-metric" :style="{ color: tone(avgMs) }">{{ ms(avgMs) }}<span v-if="delta.avg != null" class="tel-delta" :class="deltaClass(delta.avg, true)">{{ arrow(delta.avg) }} {{ deltaPct(delta.avg) }}</span></div>
                        </div>
                        <div>
                            <span class="tel-label">p95</span>
                            <div class="tel-metric" :style="{ color: tone(p95Ms) }">{{ ms(p95Ms) }}<span v-if="delta.p95 != null" class="tel-delta" :class="deltaClass(delta.p95, true)">{{ arrow(delta.p95) }} {{ deltaPct(delta.p95) }}</span></div>
                        </div>
                    </div>
                    <div style="margin-top: 0.75rem">
                        <TimeChart v-if="hasDurSeries" :time="durTime" :series="durChartSeries" :markers="chartMarkers" unit="ms" :height="210" brush @zoom="onZoom" />
                        <div v-else class="tel-chart-empty" style="height: 210px">No latency series in this window.</div>
                    </div>
                </div>
            </div>

            <!-- Secondary stats -->
            <div class="tel-grid tel-grid--cards" style="margin-bottom: 1rem">
                <div class="tel-card">
                    <span class="tel-label">Error rate</span>
                    <div class="tel-metric" :style="{ color: metrics.errorRate > 0 ? 'var(--color-red-500)' : 'var(--color-green-500)' }">{{ pct(metrics.errorRate) }}<span v-if="delta.errorRate != null" class="tel-delta" :class="deltaClass(delta.errorRate, true)">{{ arrow(delta.errorRate) }} {{ deltaPP(delta.errorRate) }}</span></div>
                    <div class="tel-sub">{{ fmt(serverCount) }} server errors (5XX)</div>
                </div>
                <div class="tel-card">
                    <span class="tel-label">Exceptions</span>
                    <div class="tel-metric" :style="{ color: exceptionCount > 0 ? 'var(--color-red-500)' : 'var(--color-green-500)' }">{{ fmt(exceptionCount) }}</div>
                    <div class="tel-sub">error-level logs{{ exceptions && exceptions.capped ? ' (capped)' : '' }}</div>
                    <div style="margin-top: 0.5rem">
                        <TimeChart v-if="exceptions && exceptions.trend.length" :time="excTime" :series="excChartSeries" unit="count" :legend="false" :height="72" />
                    </div>
                </div>
            </div>

            <!-- Routes needing attention -->
            <div v-if="metrics.routes.length" class="tel-panel" style="margin-bottom: 1rem">
                <div class="tel-panel__head">Routes needing attention</div>
                <Table>
                    <TableColumns>
                        <TableColumn>Route</TableColumn>
                        <TableColumn>Requests</TableColumn>
                        <TableColumn>Errors</TableColumn>
                        <TableColumn>p95</TableColumn>
                        <TableColumn>Trend</TableColumn>
                    </TableColumns>
                    <TableRows>
                        <TableRow v-for="r in metrics.routes" :key="r.route">
                            <TableCell class="break-all">
                                <Link :href="routeHref(r.route)" class="no-underline hover:underline">{{ r.route }}</Link>
                            </TableCell>
                            <TableCell class="tel-num">{{ fmt(r.total) }}</TableCell>
                            <TableCell class="tel-num">
                                <span :style="{ color: r.errors > 0 ? 'var(--color-red-500)' : 'inherit' }">{{ fmt(r.errors) }}</span>
                                <span v-if="r.errors > 0" class="tel-sub" style="margin-left: 0.3rem">{{ pct(r.errorRate) }}</span>
                            </TableCell>
                            <TableCell class="tel-num" :style="{ color: tone(r.p95) }">{{ ms(r.p95) }}</TableCell>
                            <TableCell><div class="tel-sparkline"><Chart :points="r.trend" :height="24" color="var(--color-gray-500)" /></div></TableCell>
                        </TableRow>
                    </TableRows>
                </Table>
            </div>

            <!-- Recent exceptions -->
            <div v-if="exceptions && exceptions.recent && exceptions.recent.length" class="tel-panel" style="margin-bottom: 1rem">
                <div class="tel-panel__head">Recent exceptions</div>
                <div>
                    <div v-for="(e, i) in exceptions.recent" :key="i" class="tel-row" style="align-items: flex-start">
                        <span class="tel-row__time">{{ time(e.timestamp) }}</span>
                        <span class="tel-row__main font-mono text-xs" style="color: var(--color-red-500)">{{ e.line }}</span>
                    </div>
                </div>
            </div>

            <!-- Recent requests (trace-derived) -->
            <div v-if="overview && overview.recent.length" class="tel-panel">
                <div class="tel-panel__head">Recent requests</div>
                <Table>
                    <TableColumns><TableColumn>When</TableColumn><TableColumn>Request</TableColumn><TableColumn>Duration</TableColumn></TableColumns>
                    <TableRows>
                        <TableRow v-for="t in overview.recent" :key="t.id">
                            <TableCell class="whitespace-nowrap text-gray-500">{{ time(t.timestampMs) }}</TableCell>
                            <TableCell><Link :href="`?trace=${t.id}`" class="no-underline hover:underline">{{ t.name }}</Link></TableCell>
                            <TableCell class="tel-num" :style="{ color: tone(t.durationMs) }">{{ ms(t.durationMs) }}</TableCell>
                        </TableRow>
                    </TableRows>
                </Table>
            </div>

            <div v-if="isOverviewEmpty" class="tel-panel">
                <p class="tel-empty">No requests in this window. Check the telemetry backend is reachable and receiving traces and metrics.</p>
            </div>
        </template>

        <!-- ───────────── Activity ───────────── -->
        <ActivitySection v-else-if="section === 'activity'" :activity="activity" :markers="chartMarkers" />

        <!-- ───────────── Statamic ───────────── -->
        <template v-else>
            <div class="tel-grid tel-grid--cards" style="margin-bottom: 1rem">
                <div class="tel-card">
                    <span class="tel-label">Static cache hit ratio</span>
                    <div class="tel-metric" :style="{ color: ratioTone }">{{ metrics.cacheHitRatio === null ? '—' : Math.round(metrics.cacheHitRatio * 100) + '%' }}</div>
                    <div class="tel-sub">served from cache</div>
                    <div v-if="metrics.cacheHitRatio !== null" class="tel-bar">
                        <div class="tel-bar__fill" :style="{ width: Math.round(metrics.cacheHitRatio * 100) + '%', background: ratioTone }" />
                    </div>
                </div>
                <div class="tel-card">
                    <span class="tel-label">Stache warms</span>
                    <div class="tel-metric">{{ fmt(metrics.stacheWarms) }}</div>
                    <div class="tel-sub">p95 warm {{ metrics.stacheWarmP95 ? ms(metrics.stacheWarmP95) : '—' }} · {{ fmt(metrics.stacheClears) }} clears</div>
                </div>
                <div class="tel-card">
                    <span class="tel-label">Glide generations</span>
                    <div class="tel-metric">{{ fmt(glideTotal) }}</div>
                    <div class="tel-sub">{{ fmt(metrics.glideClears) }} cache clears</div>
                </div>
                <div class="tel-card">
                    <span class="tel-label">Form submissions</span>
                    <div class="tel-metric">{{ fmt(formsTotal) }}</div>
                    <div class="tel-sub">{{ fmt(changesTotal) }} content changes</div>
                </div>
            </div>

            <div class="tel-grid tel-grid--wide">
                <div v-if="cacheOps.length" class="tel-panel">
                    <div class="tel-panel__head">Static cache operations</div>
                    <div class="tel-chips" style="padding: 1rem">
                        <span v-for="[op, n] in cacheOps" :key="op" class="tel-chip">{{ op }} {{ fmt(n) }}</span>
                    </div>
                </div>

                <div v-if="metrics.contentChanges.length" class="tel-panel">
                    <div class="tel-panel__head">Content changes</div>
                    <Table>
                        <TableColumns><TableColumn>Type</TableColumn><TableColumn>Action</TableColumn><TableColumn>Count</TableColumn></TableColumns>
                        <TableRows>
                            <TableRow v-for="c in metrics.contentChanges" :key="c.type + c.action">
                                <TableCell>{{ c.type }}</TableCell>
                                <TableCell><Badge :text="c.action" color="blue" size="sm" /></TableCell>
                                <TableCell class="tel-num">{{ fmt(c.count) }}</TableCell>
                            </TableRow>
                        </TableRows>
                    </Table>
                </div>

                <div v-if="metrics.glide.length" class="tel-panel">
                    <div class="tel-panel__head">Glide by preset</div>
                    <Table>
                        <TableColumns><TableColumn>Preset</TableColumn><TableColumn>Images</TableColumn></TableColumns>
                        <TableRows>
                            <TableRow v-for="g in metrics.glide" :key="g.preset"><TableCell>{{ g.preset }}</TableCell><TableCell class="tel-num">{{ fmt(g.count) }}</TableCell></TableRow>
                        </TableRows>
                    </Table>
                </div>

                <div v-if="metrics.forms.length" class="tel-panel">
                    <div class="tel-panel__head">Form submissions</div>
                    <Table>
                        <TableColumns><TableColumn>Form</TableColumn><TableColumn>Submissions</TableColumn></TableColumns>
                        <TableRows>
                            <TableRow v-for="f in metrics.forms" :key="f.form"><TableCell>{{ f.form }}</TableCell><TableCell class="tel-num">{{ fmt(f.count) }}</TableCell></TableRow>
                        </TableRows>
                    </Table>
                </div>
            </div>

            <div v-if="!hasStatamicData" class="tel-panel">
                <p class="tel-empty">No Statamic metrics in this window yet. They fill as the cache, Stache, Glide, forms and content are used (needs a shared TELEMETRY_STORE + telemetry:flush).</p>
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed, ref, onBeforeUnmount } from 'vue';
import { Header, Button, Badge, Table, TableColumns, TableColumn, TableRows, TableRow, TableCell } from '@statamic/cms/ui';
import { Head, Link, router } from '@statamic/cms/inertia';
import Chart from '../components/Chart.vue';
import TimeChart from '../components/TimeChart.vue';
import TelemetryTabs from '../components/TelemetryTabs.vue';
import ActivitySection from '../components/ActivitySection.vue';
import { count as fmt, ms, percent as pct, clock as time } from '../lib/format.js';

const props = defineProps({
    section: String,
    sections: Object,
    window: String,
    windows: Object,
    from: Number,
    to: Number,
    dashboardUrl: String,
    exploreUrl: String,
    overview: Object,
    exceptions: Object,
    deployments: { type: Array, default: () => [] },
    activity: Object,
    metrics: Object,
});

const tone = (v) => (v >= 1000 ? 'var(--color-red-500)' : v >= 300 ? 'var(--color-orange-500)' : 'var(--color-green-500)');

// ── Window + deploy markers ────────────────────────────────────────────────
const windowSeconds = computed(() => {
    const m = /^(\d+)([mh])$/.exec(props.window || '1h');
    return m ? +m[1] * (m[2] === 'h' ? 3600 : 60) : 3600;
});
const bounds = computed(() => {
    if (props.to) return { start: props.from * 1000, end: props.to * 1000 };
    const end = Date.now();
    return { start: end - windowSeconds.value * 1000, end };
});
const chartMarkers = computed(() =>
    (props.deployments || [])
        .filter((d) => d.timestamp >= bounds.value.start && d.timestamp <= bounds.value.end)
        .map((d) => ({ time: d.timestamp, label: `${time(d.timestamp)} · ${d.notes || d.id}` })),
);

// ── Requests (stacked throughput by status class) ─────────────────────────────
const sc = () => props.metrics?.statusClasses || {};
const requestCount = computed(() => (props.metrics?.requestTotal > 0 ? props.metrics.requestTotal : props.overview?.count || 0));
const okCount = computed(() => (sc()['1xx'] || 0) + (sc()['2xx'] || 0) + (sc()['3xx'] || 0));
const clientCount = computed(() => sc()['4xx'] || 0);
const serverCount = computed(() => sc()['5xx'] || 0);
const reqTime = computed(() => (props.metrics?.statusSeries?.t || []).map((t) => t * 1000));
const reqChartSeries = computed(() => {
    const s = props.metrics?.statusSeries || {};
    return [
        { name: '1/2/3XX', data: s.ok || [], color: 'var(--color-gray-400)', type: 'bar', stack: 'req' },
        { name: '4XX', data: s.client || [], color: 'var(--color-amber-500)', type: 'bar', stack: 'req' },
        { name: '5XX', data: s.server || [], color: 'var(--color-red-500)', type: 'bar', stack: 'req' },
    ];
});
const hasReqSeries = computed(() => reqChartSeries.value.some((b) => b.data.some((v) => v > 0)));

// ── Duration (avg + p95 over time; trace fallback so tiles never blank) ────────
const avgMs = computed(() => (props.metrics?.avg > 0 ? props.metrics.avg : props.overview?.p50 || 0));
const p95Ms = computed(() => (props.metrics?.p95 > 0 ? props.metrics.p95 : props.overview?.p95 || 0));
const durTime = computed(() => (props.metrics?.durationSeries?.t || []).map((t) => t * 1000));
const durChartSeries = computed(() => {
    const d = props.metrics?.durationSeries || {};
    return [
        { name: 'avg', data: d.avg || [], color: 'var(--color-gray-400)' },
        { name: 'p95', data: d.p95 || [], color: 'var(--color-amber-500)' },
    ];
});
const hasDurSeries = computed(() => durChartSeries.value.some((b) => b.data.some((v) => v > 0)));

// ── Exceptions over time ──────────────────────────────────────────────────────
const exceptionCount = computed(() => props.exceptions?.count || 0);
const excTime = computed(() => {
    const n = props.exceptions?.trend?.length || 0;
    if (!n) return [];
    const end = Date.now();
    const start = end - windowSeconds.value * 1000;
    const span = end - start;
    return Array.from({ length: n }, (_, i) => start + ((i + 0.5) / n) * span);
});
const excChartSeries = computed(() => [{ name: 'Errors', data: props.exceptions?.trend || [], color: 'var(--color-red-500)', type: 'bar' }]);

// ── Period-over-period deltas (telemetry-ui rules: 1% dead-band) ───────────────
const delta = computed(() => props.metrics?.delta || {});
const DEAD = 0.01;
const arrow = (d) => (d > DEAD ? '▲' : d < -DEAD ? '▼' : '±');
const deltaPct = (d) => { const p = Math.abs(d * 100); return (p >= 10 ? Math.round(p) : p.toFixed(1)) + '%'; };
const deltaPP = (d) => { const p = Math.abs(d * 100); return (p >= 10 ? Math.round(p) : p.toFixed(1)) + 'pp'; };
const deltaClass = (d, badWhenUp) => { if (Math.abs(d) <= DEAD) return ''; return (badWhenUp ? d > 0 : d < 0) ? 'tel-delta--bad' : 'tel-delta--good'; };

const isOverviewEmpty = computed(
    () => requestCount.value <= 0 && exceptionCount.value <= 0 && !props.overview?.recent?.length && !props.metrics?.routes?.length,
);

const routeHref = (route) => `${props.exploreUrl}?route=${encodeURIComponent(route)}&window=${props.window}`;
const statusHref = (cls) => `${props.exploreUrl}?signal=requests&status=${cls}&window=${props.window}`;

// ── Statamic section ──────────────────────────────────────────────────────────
const cacheOps = computed(() => Object.entries(props.metrics?.cacheOperations || {}));
const glideTotal = computed(() => (props.metrics?.glide || []).reduce((s, g) => s + g.count, 0));
const formsTotal = computed(() => (props.metrics?.forms || []).reduce((s, f) => s + f.count, 0));
const changesTotal = computed(() => (props.metrics?.contentChanges || []).reduce((s, c) => s + c.count, 0));
const hasStatamicData = computed(() => cacheOps.value.length || props.metrics?.stacheWarms > 0 || glideTotal.value > 0 || formsTotal.value > 0 || changesTotal.value > 0);
const ratioTone = computed(() => {
    const r = props.metrics?.cacheHitRatio;
    if (r === null || r === undefined) return 'var(--color-gray-500)';
    return r >= 0.8 ? 'var(--color-green-500)' : r >= 0.4 ? 'var(--color-orange-500)' : 'var(--color-red-500)';
});

function changeWindow(value) {
    router.get(location.pathname, { section: props.section, window: value }, { preserveScroll: true, preserveState: false });
}

// Brush-to-zoom: drag a range on a chart to re-query exactly that window.
function onZoom([fromMs, toMs]) {
    router.get(
        location.pathname,
        { section: props.section, window: props.window, from: Math.round(fromMs / 1000), to: Math.round(toMs / 1000) },
        { preserveScroll: true, preserveState: false },
    );
}
function resetRange() {
    router.get(location.pathname, { section: props.section, window: props.window }, { preserveScroll: true, preserveState: false });
}
const rangeLabel = computed(() => (props.to ? `${time(props.from * 1000)}–${time(props.to * 1000)}` : ''));

// Live auto-refresh: re-fetch the current view every 15s without losing scroll.
const live = ref(false);
let timer = null;
function toggleLive() {
    live.value = !live.value;
    if (live.value) timer = setInterval(() => router.reload({ preserveScroll: true, preserveState: true }), 15000);
    else if (timer) {
        clearInterval(timer);
        timer = null;
    }
}
onBeforeUnmount(() => timer && clearInterval(timer));
</script>
