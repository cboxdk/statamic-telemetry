<template>
    <TelemetryPage :title="__('Explore')" :nav="nav" :window="window" :windows="windows" :from="from" :to="to" :carry="carryPage">
        <!-- Stat line + distribution charts (requests / traces) -->
        <template v-if="isTrace">
            <StatHeader :items="statItems" />
            <div v-if="hasThroughput || hasHeatmap" class="grid gap-6" :class="{ 'xl:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]': hasThroughput && hasHeatmap }">
                <Card v-if="hasThroughput" class="space-y-2">
                    <div class="text-[10.5px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Throughput over time') }}</div>
                    <TimeChart :time="throughputTime" :series="throughputChartSeries" :markers="chartMarkers" unit="count" :legend="false" :height="130" brush @zoom="onZoom" />
                </Card>
                <Card v-if="hasHeatmap" class="space-y-2">
                    <div class="text-[10.5px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Latency × time') }}</div>
                    <HeatChart :cols="heatmap.cols" :bands="heatmap.bands" :cells="heatmap.cells" :max="heatmap.max" :height="130" />
                </Card>
            </div>
        </template>

        <Card inset>
            <!-- Toolbar -->
            <div class="flex flex-wrap items-center gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                <ToggleGroup :model-value="signal" size="sm" @update:model-value="changeSignal">
                    <ToggleItem v-for="(label, key) in signals" :key="key" :value="key" :label="label" />
                </ToggleGroup>
                <div class="ms-auto flex items-center gap-2">
                    <Input v-model="searchText" type="search" :placeholder="__('Search…')" class="w-56" @keyup.enter="applySearch" />
                    <Button size="sm" :text="__('Search')" @click="applySearch" />
                    <Button v-if="signal !== 'traces'" size="sm" :variant="tailing ? 'primary' : 'default'" :text="tailing ? '● Live' : 'Live tail'" @click="toggleTail" />
                </div>
            </div>

            <!-- Active filters -->
            <div v-if="hasFilters || groupBy" class="flex flex-wrap items-center gap-2 border-b border-gray-200 px-4 py-2 dark:border-gray-800">
                <span class="text-[10.5px] font-semibold uppercase tracking-wider text-gray-500">Where</span>
                <button v-for="(value, dim) in filters" :key="dim" type="button" class="cursor-pointer" :title="__('Remove :dim filter', { dim: dimLabel(dim) })" @click="toggleFacet(dim, value)">
                    <Badge :text="`${dimLabel(dim)} = ${value}`" append="✕" color="blue" size="sm" />
                </button>
                <Badge v-if="groupBy" :text="`grouped by ${dimLabel(groupBy)}`" color="violet" size="sm" />
                <Button size="xs" variant="ghost" :text="__('Clear')" @click="clearFilters" />
            </div>

            <div class="grid" :class="{ 'md:grid-cols-[250px_minmax(0,1fr)]': isTrace && facets }">
                <!-- Facets -->
                <aside v-if="isTrace && facets" class="max-h-[70vh] space-y-5 overflow-y-auto border-gray-200 p-3 md:border-e dark:border-gray-800">
                    <div v-for="dim in facetDims" :key="dim" v-show="facets[dim] && facets[dim].length">
                        <div class="mb-1 flex items-center justify-between">
                            <span class="text-[10.5px] font-semibold uppercase tracking-wider text-gray-500">{{ dimLabel(dim) }}</span>
                            <Button v-if="GROUPABLE.includes(dim)" size="2xs" :variant="groupBy === dim ? 'primary' : 'ghost'" :text="__('group by')" @click="toggleGroup(dim)" />
                        </div>
                        <div class="space-y-0.5">
                            <button
                                v-for="f in facets[dim]"
                                :key="f.value"
                                type="button"
                                class="relative flex w-full items-center justify-between gap-2 overflow-hidden rounded-md px-2 py-1 text-left text-xs transition-colors hover:bg-gray-100 dark:hover:bg-gray-800"
                                :class="{ 'bg-blue-500/10 font-medium': filters[dim] === String(f.value) }"
                                @click="toggleFacet(dim, f.value)"
                            >
                                <span class="absolute inset-y-0 start-0 bg-gray-500/10" :style="{ width: barPct(dim, f.count) }" />
                                <span class="relative z-10 truncate font-mono" :title="f.value">{{ f.value }}</span>
                                <span class="relative z-10 shrink-0 tabular-nums text-gray-400">{{ fmt(f.count) }}</span>
                            </button>
                        </div>
                    </div>
                </aside>

                <!-- Results -->
                <section class="min-w-0">
                    <!-- Group-by table -->
                    <div v-if="grouped" class="text-sm">
                        <div class="grid grid-cols-[minmax(0,1fr)_5.5rem_6.5rem_5rem_5rem] gap-3 border-b border-gray-200 px-4 py-2 text-[10.5px] font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-800">
                            <div>{{ dimLabel(groupBy) }}</div><div class="text-end">{{ __('Requests') }}</div><div class="text-end">{{ __('Errors') }}</div><div class="text-end">p50</div><div class="text-end">p95</div>
                        </div>
                        <button
                            v-for="g in groups"
                            :key="g.value"
                            type="button"
                            class="grid w-full grid-cols-[minmax(0,1fr)_5.5rem_6.5rem_5rem_5rem] items-center gap-3 border-b border-gray-100 px-4 py-2 text-left transition-colors hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800"
                            @click="drillInto(groupBy, g.value)"
                        >
                            <span class="relative block overflow-hidden rounded px-1.5 py-0.5">
                                <span class="absolute inset-y-0 start-0 bg-blue-500/10" :style="{ width: Math.round((g.count / groupMax) * 100) + '%' }" />
                                <span class="relative z-10 truncate font-mono text-xs">{{ g.value }}</span>
                            </span>
                            <span class="text-end tabular-nums">{{ fmt(g.count) }}</span>
                            <span class="text-end tabular-nums"><span :class="g.errors > 0 ? 'text-red-500' : ''">{{ fmt(g.errors) }}</span><span v-if="g.errors > 0" class="ms-1 text-xs text-gray-500">{{ pct(g.errorRate) }}</span></span>
                            <span class="text-end tabular-nums" :class="toneClass(g.p50)">{{ ms(g.p50) }}</span>
                            <span class="text-end tabular-nums" :class="toneClass(g.p95)">{{ ms(g.p95) }}</span>
                        </button>
                    </div>

                    <!-- Lists -->
                    <div v-else class="max-h-[70vh] overflow-y-auto" scroll-region>
                        <div v-if="!items.length" class="p-10 text-center text-sm text-gray-500">{{ __('Nothing in this window.') }}</div>

                        <!-- requests / traces → trace drawer -->
                        <template v-else-if="isTrace">
                            <RequestRow v-for="t in items" :key="t.id" :row="t" :active="openTraceId === t.id" :filters="filters" @open="openTrace" @facet="toggleFacet" />
                        </template>

                        <!-- logs / errors → inline expand -->
                        <template v-else>
                            <div v-for="l in items" :key="l.id" class="border-b border-gray-100 dark:border-gray-800">
                                <button type="button" class="grid w-full grid-cols-[4.5rem_auto_minmax(0,1fr)] items-start gap-3 px-4 py-2 text-left text-xs transition-colors hover:bg-gray-500/10" @click="toggleLog(l.id)">
                                    <span class="tabular-nums text-gray-500">{{ time(l.timestamp) }}</span>
                                    <Badge :text="l.level" :color="levelColor(l.level)" size="sm" />
                                    <span class="min-w-0">
                                        <span class="block font-mono" :class="expandedLog === l.id ? 'whitespace-pre-wrap break-words' : 'truncate'">{{ l.line }}</span>
                                        <!-- The event's own facts (what query, which page, how many times) — the line alone is just a name. -->
                                        <span v-if="logSummary(l)" class="block truncate font-mono text-[11px] text-gray-500">{{ logSummary(l) }}</span>
                                    </span>
                                </button>
                                <div v-if="expandedLog === l.id" class="grid grid-cols-[10rem_1fr] gap-x-3 gap-y-1 bg-gray-500/5 px-4 py-3 text-xs">
                                    <template v-for="(v, k) in l.labels" :key="k">
                                        <div class="truncate text-gray-500">{{ k }}</div>
                                        <div class="break-words font-mono">{{ v }}</div>
                                    </template>
                                    <div v-if="l.traceId" class="col-span-2 pt-1">
                                        <Button size="xs" :text="__('Open trace')" @click="openTrace(l.traceId)" />
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </section>
            </div>
        </Card>
    </TelemetryPage>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { Badge, Button, Card, Input, ToggleGroup, ToggleItem } from '@statamic/cms/ui';
import { usePoll } from '@statamic/cms/inertia';
import TelemetryPage from '../components/TelemetryPage.vue';
import RequestRow from '../components/RequestRow.vue';
import StatHeader from '../components/StatHeader.vue';
import TimeChart from '../components/TimeChart.vue';
import HeatChart from '../components/HeatChart.vue';
import { useTelemetryUrl, rangeBounds } from '../lib/useTelemetryUrl.js';
import { count as fmt, ms, percent as pct, clock as time } from '../lib/format.js';

const props = defineProps({
    signal: String,
    signals: Object,
    nav: { type: Array, default: () => [] },
    window: String,
    windows: Object,
    from: Number,
    to: Number,
    dashboardUrl: String,
    exploreUrl: String,
    q: String,
    filters: { type: Object, default: () => ({}) },
    groupBy: String,
    groups: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    summary: Object,
    facets: Object,
    throughput: Object,
    heatmap: Object,
    deployments: { type: Array, default: () => [] },
});

const searchText = ref(props.q || '');
watch(() => props.q, (q) => (searchText.value = q || ''));

// Statamic's dimensions first — what was served — then the HTTP ones.
const facetDims = ['collection', 'taxonomy', 'type', 'cache', 'site', 'status', 'method', 'route'];
const GROUPABLE = ['status', 'method', 'route']; // backed by metric labels
const FILTER_KEYS = [...facetDims, 'path'];
const dimLabel = (d) => __({ collection: 'Collection', taxonomy: 'Taxonomy', type: 'Content type', cache: 'Static cache', site: 'Site', status: 'Status', method: 'Method', route: 'Route', path: 'Path' }[d] || d);
const noFilters = () => Object.fromEntries(FILTER_KEYS.map((k) => [k, null]));

const isTrace = computed(() => props.signal === 'requests' || props.signal === 'traces');
const hasFilters = computed(() => Object.keys(props.filters || {}).length > 0);
const grouped = computed(() => !!props.groupBy && props.groups.length > 0);

// ── URL / state: everything the page owns rides along on every navigation ─────
const carryPage = () => ({
    window: props.window, from: props.from, to: props.to,
    signal: props.signal, q: searchText.value, ...(props.filters || {}), groupBy: props.groupBy,
});
const { go, replaceDrawer, topDrawer } = useTelemetryUrl(carryPage);

const changeSignal = (s) => s && go({ ...noFilters(), signal: s, q: null, groupBy: null, drawer: null });
const applySearch = () => go({ q: searchText.value || null });
const toggleFacet = (dim, value) => go({ [dim]: props.filters[dim] === String(value) ? null : String(value) });
const clearFilters = () => go({ ...noFilters(), groupBy: null });
const toggleGroup = (dim) => go({ groupBy: props.groupBy === dim ? null : dim });
const drillInto = (dim, value) => go({ [dim]: String(value), groupBy: null });
const onZoom = ([fromMs, toMs]) => go({ from: Math.round(fromMs / 1000), to: Math.round(toMs / 1000) });

// Opening a trace only changes the drawer param — no page props re-fetch.
const openTrace = (id) => replaceDrawer({ kind: 'trace', id }, { only: [] });
const openTraceId = computed(() => (topDrawer.value?.kind === 'trace' ? topDrawer.value.id : null));

// j / k (or arrows) step the open trace through the list.
function onKey(e) {
    if (!openTraceId.value || !isTrace.value || e.target.closest('input, textarea, select')) return;
    const dir = e.key === 'j' || e.key === 'ArrowDown' ? 1 : e.key === 'k' || e.key === 'ArrowUp' ? -1 : 0;
    if (!dir) return;
    const i = props.items.findIndex((t) => t.id === openTraceId.value);
    const next = props.items[i + dir];
    if (next) { e.preventDefault(); openTrace(next.id); }
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

// ── Logs inline detail ─────────────────────────────────────────────────────────
const expandedLog = ref(null);
const toggleLog = (id) => (expandedLog.value = expandedLog.value === id ? null : id);
// The labels that explain an event, in the order a person wants them.
const SUMMARY_KEYS = [
    'exception_message', 'statamic_content_action', 'statamic_content_type', 'statamic_content_title',
    'laravel_db_query_shape', 'db_query_text', 'db_query_repeat_count', 'laravel_http_client_shape', 'http_client_repeat_count',
    'statamic_glide_preset', 'statamic_form', 'url_path', 'http_route',
];
const logSummary = (l) => {
    const parts = [];
    for (const k of SUMMARY_KEYS) {
        const v = l.labels?.[k];
        if (!v) continue;
        parts.push(k.endsWith('repeat_count') ? `×${v}` : String(v).slice(0, 120));
        if (parts.length === 3) break;
    }
    return parts.join(' · ');
};
const levelColor = (l) => ({ error: 'red', critical: 'red', alert: 'red', warning: 'orange', warn: 'orange', notice: 'blue', info: 'blue', debug: 'default' }[(l || '').toLowerCase()] || 'default');
const toneClass = (v) => (v >= 1000 ? 'text-red-500' : v >= 300 ? 'text-orange-500' : 'text-green-600 dark:text-green-400');

// ── Live tail: just the list, every 5s ─────────────────────────────────────────
const tailing = ref(false);
const poll = usePoll(5000, { only: ['items'], preserveScroll: true, preserveState: true }, { autoStart: false });
function toggleTail() {
    tailing.value = !tailing.value;
    tailing.value ? poll.start() : poll.stop();
}

// ── Stats, facets, charts ──────────────────────────────────────────────────────
const statItems = computed(() => {
    const s = props.summary;
    if (!s) return [];
    return [
        { label: __('Requests'), value: fmt(s.requests) },
        { label: __('Error rate'), value: pct(s.errorRate), tone: s.errorRate > 0.01 ? 'var(--color-red-500)' : undefined },
        { label: 'P50', value: ms(s.p50) },
        { label: 'P95', value: ms(s.p95), tone: s.p95 >= 1000 ? 'var(--color-orange-500)' : undefined },
        { label: __('Showing'), value: fmt(props.items.length) },
    ];
});
const facetMax = (dim) => Math.max(1, ...(props.facets?.[dim] || []).map((f) => f.count));
const barPct = (dim, c) => Math.round((c / facetMax(dim)) * 100) + '%';
const groupMax = computed(() => Math.max(1, ...(props.groups || []).map((g) => g.count)));

const bounds = computed(() => rangeBounds(props));
const chartMarkers = computed(() =>
    (props.deployments || [])
        .filter((d) => d.timestamp >= bounds.value[0] && d.timestamp <= bounds.value[1])
        .map((d) => ({ time: d.timestamp, label: `${time(d.timestamp)} · ${d.notes || d.id}` })),
);
const throughputTime = computed(() => (props.throughput?.t || []).map((t) => t * 1000));
const throughputChartSeries = computed(() => {
    const t = props.throughput || {};
    return [
        { name: '1/2/3XX', data: t.ok || [], color: 'var(--color-gray-400)', type: 'bar', stack: 'tp' },
        { name: '4XX', data: t.client || [], color: 'var(--color-amber-500)', type: 'bar', stack: 'tp' },
        { name: '5XX', data: t.server || [], color: 'var(--color-red-500)', type: 'bar', stack: 'tp' },
    ];
});
const hasThroughput = computed(() => throughputChartSeries.value.some((b) => b.data.some((v) => v > 0)));
const hasHeatmap = computed(() => (props.heatmap?.cells?.length || 0) > 0);
</script>
