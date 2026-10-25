<template>
    <TelemetryPage title="Telemetry" :nav="nav" :window="window" :windows="windows" :from="from" :to="to">
        <!-- ───────────── Overview ───────────── -->
            <div v-if="deployments.length" class="flex flex-wrap items-center gap-2">
                <span class="text-[10.5px] font-semibold uppercase tracking-wider text-gray-500">Releases</span>
                <Badge v-for="d in deployments" :key="d.timestamp + d.id" :text="`${time(d.timestamp)} · ${d.notes || d.id}`" color="violet" size="sm" pill />
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Requests -->
                <Card class="flex flex-col gap-4">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="text-sm font-semibold">Requests</h3>
                        <span class="text-xs text-gray-500">per step, by response class</span>
                    </div>
                    <div class="grid grid-cols-[repeat(auto-fit,minmax(6.5rem,1fr))] gap-3">
                        <Tile label="Requests" :value="fmt(requestCount)" :delta="deltaFmt(delta.requests)" :tone="deltaTone(delta.requests)" />
                        <Tile label="1/2/3XX" :value="fmt(okCount)" dim />
                        <Tile label="4XX" :value="fmt(clientCount)" :tone="clientCount > 0 ? 'text-amber-600 dark:text-amber-400' : ''" :href="statusHref('4xx')" />
                        <Tile label="5XX" :value="fmt(serverCount)" :tone="serverCount > 0 ? 'text-red-500' : ''" :href="statusHref('5xx')" />
                        <Tile label="Error rate" :value="pct(metrics?.errorRate)" :delta="deltaPp(delta.errorRate)" :tone="deltaTone(delta.errorRate, true)" />
                    </div>
                    <div class="h-52">
                        <TimeChart v-if="hasReqSeries" :time="reqTime" :series="reqChartSeries" :markers="chartMarkers" unit="count" :smooth="false" :height="208" brush @zoom="onZoom" />
                        <Empty v-else>No request series in this window.</Empty>
                    </div>
                </Card>

                <!-- Duration -->
                <Card class="flex flex-col gap-4">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="text-sm font-semibold">Duration</h3>
                        <span class="text-xs text-gray-500">server-side latency</span>
                    </div>
                    <div class="grid grid-cols-[repeat(auto-fit,minmax(6.5rem,1fr))] gap-3">
                        <Tile label="Avg" :value="ms(avgMs)" :delta="deltaFmt(delta.avg)" :tone="deltaTone(delta.avg, true)" />
                        <Tile label="p50" :value="ms(metrics?.p50 || overview?.p50)" dim />
                        <Tile label="p95" :value="ms(p95Ms)" :delta="deltaFmt(delta.p95)" :tone="deltaTone(delta.p95, true)" />
                        <Tile label="p99" :value="ms(metrics?.p99)" dim />
                    </div>
                    <div class="h-52">
                        <TimeChart v-if="hasDurSeries" :time="durTime" :series="durChartSeries" :markers="chartMarkers" unit="ms" :height="208" brush @zoom="onZoom" />
                        <Empty v-else>No latency series in this window.</Empty>
                    </div>
                </Card>

                <!-- Exceptions -->
                <Card class="flex flex-col gap-4">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="text-sm font-semibold">Exceptions</h3>
                        <Link :href="hrefTime(exploreUrl, { signal: 'errors' })" class="text-xs text-blue-600 no-underline hover:underline dark:text-blue-400">Open errors →</Link>
                    </div>
                    <div class="grid grid-cols-[repeat(auto-fit,minmax(6.5rem,1fr))] gap-3">
                        <Tile label="Error-level logs" :value="fmt(exceptionCount)" :tone="exceptionCount > 0 ? 'text-red-500' : 'text-green-600 dark:text-green-400'" />
                        <Tile v-if="exceptions && exceptions.capped" label="Sample" value="capped" dim />
                    </div>
                    <div class="h-24">
                        <TimeChart v-if="exceptions && exceptions.trend.length" :time="excTime" :series="excChartSeries" unit="count" :legend="false" :height="96" />
                        <Empty v-else>No errors in this window.</Empty>
                    </div>
                </Card>

                <!-- Deploys -->
                <Card class="flex flex-col gap-3">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="text-sm font-semibold">Deploys</h3>
                        <span class="text-xs text-gray-500">php artisan telemetry:deploy</span>
                    </div>
                    <ul v-if="deployments.length" class="divide-y divide-gray-200 text-sm dark:divide-gray-800">
                        <li v-for="d in deployments.slice(0, 6)" :key="'dep' + d.timestamp + d.id" class="flex items-center gap-3 py-2">
                            <span class="h-2 w-2 shrink-0 rounded-full bg-violet-500" />
                            <span class="w-20 shrink-0 tabular-nums text-xs text-gray-500">{{ time(d.timestamp) }}</span>
                            <span class="min-w-0 flex-1 truncate">{{ d.notes || d.id }}</span>
                            <span class="font-mono text-xs text-gray-400">{{ String(d.id).slice(0, 8) }}</span>
                        </li>
                    </ul>
                    <Empty v-else class="h-24">No deployments in this window — run <code class="font-mono">telemetry:deploy</code> from your pipeline to mark releases.</Empty>
                </Card>

                <!-- Routes needing attention -->
                <CardPanel v-if="metrics?.routes?.length" heading="Routes needing attention" subheading="Most server errors, then slowest p95" class="mb-0! lg:col-span-2">
                    <Table>
                        <TableColumns>
                            <TableColumn>Route</TableColumn><TableColumn>Requests</TableColumn><TableColumn>Errors</TableColumn><TableColumn>p95</TableColumn><TableColumn>Trend</TableColumn>
                        </TableColumns>
                        <TableRows>
                            <TableRow v-for="r in metrics.routes" :key="r.route">
                                <TableCell class="break-all"><Link :href="routeHref(r.route)" class="no-underline hover:underline">{{ r.route }}</Link></TableCell>
                                <TableCell class="tabular-nums">{{ fmt(r.total) }}</TableCell>
                                <TableCell class="tabular-nums">
                                    <span :class="r.errors > 0 ? 'text-red-500' : ''">{{ fmt(r.errors) }}</span>
                                    <span v-if="r.errors > 0" class="ms-1 text-xs text-gray-500">{{ pct(r.errorRate) }}</span>
                                </TableCell>
                                <TableCell class="tabular-nums" :class="toneClass(r.p95)">{{ ms(r.p95) }}</TableCell>
                                <TableCell><div class="h-6 w-24"><Chart :points="r.trend" :height="24" color="var(--color-gray-500)" /></div></TableCell>
                            </TableRow>
                        </TableRows>
                    </Table>
                </CardPanel>

                <!-- Recent exceptions -->
                <CardPanel v-if="exceptions?.recent?.length" heading="Recent exceptions" class="mb-0! lg:col-span-2">
                    <ul class="divide-y divide-gray-200 dark:divide-gray-800">
                        <li v-for="(e, i) in exceptions.recent" :key="i" class="flex items-start gap-3 py-2 text-xs">
                            <span class="w-16 shrink-0 tabular-nums text-gray-500">{{ time(e.timestamp) }}</span>
                            <span class="min-w-0 flex-1 break-words font-mono text-red-500">{{ e.line }}</span>
                        </li>
                    </ul>
                </CardPanel>

                <!-- Recent requests → trace drawer -->
                <CardPanel v-if="overview?.recent?.length" heading="Recent requests" subheading="Click a request to open its trace" class="mb-0! lg:col-span-2">
                    <Table>
                        <TableColumns><TableColumn>When</TableColumn><TableColumn>Request</TableColumn><TableColumn>Duration</TableColumn></TableColumns>
                        <TableRows>
                            <TableRow v-for="t in overview.recent" :key="t.id" class="cursor-pointer" @click="openTrace(t.id)">
                                <TableCell class="whitespace-nowrap text-gray-500">{{ time(t.timestampMs) }}</TableCell>
                                <TableCell>
                                    <span class="font-mono text-[13px] hover:underline">{{ attr(t, 'http.request.method') ? attr(t, 'http.request.method') + ' ' : '' }}{{ attr(t, 'url.path') || t.name }}</span>
                                    <span class="ms-2 text-[11px] text-gray-500">
                                        <span v-if="attr(t, 'statamic.content.title')">{{ attr(t, 'statamic.content.title') }} · </span>
                                        <span v-if="attr(t, 'statamic.collection')" class="font-mono">entry:{{ attr(t, 'statamic.collection') }}</span>
                                        <span v-else-if="attr(t, 'http.route') && attr(t, 'http.route') !== attr(t, 'url.path')" class="font-mono">{{ attr(t, 'http.route') }}</span>
                                        <span v-if="attr(t, 'statamic.static_cache')" :class="attr(t, 'statamic.static_cache') === 'hit' ? 'text-green-600 dark:text-green-400' : 'text-amber-600 dark:text-amber-400'"> · cache {{ attr(t, 'statamic.static_cache') }}</span>
                                    </span>
                                </TableCell>
                                <TableCell class="tabular-nums" :class="toneClass(t.durationMs)">{{ ms(t.durationMs) }}</TableCell>
                            </TableRow>
                        </TableRows>
                    </Table>
                </CardPanel>
            </div>

            <Card v-if="isOverviewEmpty">
                <p class="py-10 text-center text-sm text-gray-500">No requests in this window. Check the telemetry backend is reachable and receiving traces and metrics.</p>
            </Card>

        <!-- ───────────── Activity ───────────── -->
    </TelemetryPage>
</template>

<script setup>
import { computed, defineComponent, h } from 'vue';
import { Badge, Card, CardPanel, Table, TableColumns, TableColumn, TableRows, TableRow, TableCell } from '@statamic/cms/ui';
import { Link } from '@statamic/cms/inertia';
import TelemetryPage from '../components/TelemetryPage.vue';
import Tile from '../components/Tile.vue';
import Chart from '../components/Chart.vue';
import TimeChart from '../components/TimeChart.vue';
import { useTelemetryUrl, rangeBounds } from '../lib/useTelemetryUrl.js';
import { count as fmt, ms, percent as pct, clock as time } from '../lib/format.js';

const props = defineProps({
    nav: { type: Array, default: () => [] },
    window: String,
    windows: Object,
    from: Number,
    to: Number,
    dashboardUrl: String,
    exploreUrl: String,
    overview: Object,
    exceptions: Object,
    deployments: { type: Array, default: () => [] },
    metrics: Object,
});

// ── Small presentational helper ───────────────────────────────────────────────
const Empty = defineComponent({
    setup(_, { slots, attrs }) {
        // Inner <p> so inline children (e.g. <code>) stay in the sentence instead of becoming flex items.
        return () => h('div', { class: ['flex h-full items-center justify-center text-xs text-gray-500', attrs.class] }, h('p', { class: 'text-center' }, slots.default?.()));
    },
});

// ── URL / state ────────────────────────────────────────────────────────────────
const { go, href, pushDrawer } = useTelemetryUrl(() => ({ window: props.window, from: props.from, to: props.to }));
const hrefTime = (path, extra = {}) => href(path, extra);
const routeHref = (route) => hrefTime(props.exploreUrl, { signal: 'requests', route });
const attr = (t, key) => t.attributes?.[key] || null;
const statusHref = (cls) => hrefTime(props.exploreUrl, { signal: 'requests', status: cls });
const onZoom = ([fromMs, toMs]) => go({ from: Math.round(fromMs / 1000), to: Math.round(toMs / 1000) });
const openTrace = (id) => pushDrawer({ kind: 'trace', id }, { only: [] });

const toneClass = (v) => (v >= 1000 ? 'text-red-500' : v >= 300 ? 'text-orange-500' : 'text-green-600 dark:text-green-400');

// ── Range + deploy markers ────────────────────────────────────────────────────
const bounds = computed(() => rangeBounds(props));
const chartMarkers = computed(() =>
    (props.deployments || [])
        .filter((d) => d.timestamp >= bounds.value[0] && d.timestamp <= bounds.value[1])
        .map((d) => ({ time: d.timestamp, label: `${time(d.timestamp)} · ${d.notes || d.id}` })),
);

// ── Requests ───────────────────────────────────────────────────────────────────
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

// ── Duration (trace-derived fallback so tiles never blank) ────────────────────
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

// ── Exceptions (bucketed on the real range, so brushing stays honest) ─────────
const exceptionCount = computed(() => props.exceptions?.count || 0);
const excTime = computed(() => {
    const n = props.exceptions?.trend?.length || 0;
    if (!n) return [];
    const [start, end] = bounds.value;
    return Array.from({ length: n }, (_, i) => start + ((i + 0.5) / n) * (end - start));
});
const excChartSeries = computed(() => [{ name: 'Errors', data: props.exceptions?.trend || [], color: 'var(--color-red-500)', type: 'bar' }]);

const isOverviewEmpty = computed(
    () => requestCount.value <= 0 && exceptionCount.value <= 0 && !props.overview?.recent?.length && !props.metrics?.routes?.length,
);

// ── Deltas (telemetry-ui rules: ±1% dead-band, ▲/▼/±) ─────────────────────────
const delta = computed(() => props.metrics?.delta || {});
const DEAD = 0.01;
const arrow = (d) => (d > DEAD ? '▲' : d < -DEAD ? '▼' : '±');
const deltaFmt = (d) => {
    if (d == null) return '';
    const p = Math.abs(d * 100);
    return `${arrow(d)} ${p >= 10 ? Math.round(p) : p.toFixed(1)}%`;
};
const deltaPp = (d) => {
    if (d == null) return '';
    const p = Math.abs(d * 100);
    return `${arrow(d)} ${p >= 10 ? Math.round(p) : p.toFixed(1)}pp`;
};
const deltaTone = (d, badWhenUp = false) => {
    if (d == null || Math.abs(d) <= DEAD) return '';
    return (badWhenUp ? d > 0 : d < 0) ? 'text-red-500' : 'text-green-600 dark:text-green-400';
};

</script>
