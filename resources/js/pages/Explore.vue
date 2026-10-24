<template>
    <div>
        <Head title="Explore" />
        <Header title="Explore" icon="chart-monitoring-indicator">
            <button v-if="to" @click="resetRange" class="tel-chip tel-chip--blue" style="cursor: pointer" :title="`Reset to ${windows[window]}`">{{ rangeLabel }} ✕</button>
            <select :value="window" @change="changeWindow($event.target.value)" class="h-9 rounded-lg border border-gray-300 bg-white px-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                <option v-for="(label, value) in windows" :key="value" :value="value">{{ label }}</option>
            </select>
        </Header>

        <TelemetryTabs active="explore" :window="window" :dashboard-url="dashboardUrl" :explore-url="exploreUrl" />

        <!-- Stat header + throughput (requests / traces) -->
        <template v-if="isTrace">
            <StatHeader :items="statItems" style="margin-bottom: 0.75rem" />
            <div v-if="hasThroughput || hasHeatmap" style="display: grid; gap: 0.75rem; grid-template-columns: repeat(auto-fit, minmax(22rem, 1fr)); margin-bottom: 0.75rem">
                <div v-if="hasThroughput" class="tel-panel" style="padding: 0.5rem 0.75rem 0.15rem">
                    <div class="tel-label" style="padding: 0.15rem 0.1rem 0">Throughput over time</div>
                    <TimeChart :time="throughputTime" :series="throughputChartSeries" :markers="chartMarkers" unit="count" :legend="false" :height="150" brush @zoom="onZoom" />
                </div>
                <div v-if="hasHeatmap" class="tel-panel" style="padding: 0.5rem 0.75rem 0.15rem">
                    <div class="tel-label" style="padding: 0.15rem 0.1rem 0">Latency × time</div>
                    <HeatChart :cols="heatmap.cols" :bands="heatmap.bands" :cells="heatmap.cells" :max="heatmap.max" :height="150" />
                </div>
            </div>
        </template>

        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <nav class="flex gap-1 rounded-lg bg-gray-500/10 p-1">
                <button
                    v-for="(label, key) in signals"
                    :key="key"
                    @click="changeSignal(key)"
                    class="rounded-md px-3 py-1 text-sm font-medium"
                    :class="key === signal ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-700 dark:text-white' : 'text-gray-500'"
                >{{ label }}</button>
            </nav>
            <div class="flex items-center gap-2">
                <input v-model="searchText" @keyup.enter="applySearch" type="search" placeholder="Search…" class="h-9 w-56 rounded-lg border border-gray-300 bg-white px-2.5 text-sm dark:border-gray-700 dark:bg-gray-800" />
                <Button size="sm" variant="ghost" @click="applySearch">Search</Button>
                <Button size="sm" :variant="tailing ? 'primary' : 'ghost'" @click="toggleTail">{{ tailing ? '● Live' : 'Live tail' }}</Button>
            </div>
        </div>

        <div class="tel-split">
            <!-- Facet rail (requests / traces) -->
            <aside v-if="isTrace && facets" class="tel-facets">
                <div v-for="dim in facetDims" :key="dim" v-show="facets[dim] && facets[dim].length" class="tel-facet-group">
                    <div class="tel-facet-group__label tel-label">{{ dimLabel(dim) }}</div>
                    <div>
                        <button
                            v-for="f in facets[dim]"
                            :key="f.value"
                            @click="toggleFacet(dim, f.value)"
                            class="tel-facet"
                            :class="{ 'tel-facet--active': filters[dim] === String(f.value) }"
                        >
                            <span class="tel-facet__bar" :style="{ width: barPct(dim, f.count) }"></span>
                            <span class="tel-facet__value" :title="f.value">{{ f.value }}</span>
                            <span class="tel-facet__count">{{ fmt(f.count) }}</span>
                        </button>
                    </div>
                </div>
                <button v-if="hasFilters" @click="clearFilters" class="text-xs text-blue-500 hover:underline" style="margin-top: 0.75rem">Clear filters</button>
            </aside>

            <Panel inset class="min-w-0 flex-1" style="flex: 1; min-width: 0">
                <SplitterGroup direction="horizontal" class="h-[32rem]">
                    <SplitterPanel :default-size="45" :min-size="25">
                        <div class="h-full overflow-auto">
                            <div v-if="!items.length" class="p-10 text-center text-sm text-gray-500">Nothing in this window.</div>

                            <!-- requests / traces -->
                            <template v-else-if="isTrace">
                                <button v-for="t in items" :key="t.id" @click="select(t)" class="tel-row" :class="{ 'tel-row--active': t.id === activeId }">
                                    <span class="tel-row__time">{{ time(t.timestamp) }}</span>
                                    <span class="tel-row__main">{{ t.name }}</span>
                                    <span class="tel-num shrink-0" :style="{ color: tone(t.durationMs) }">{{ ms(t.durationMs) }}</span>
                                </button>
                            </template>

                            <!-- logs / errors -->
                            <template v-else>
                                <button v-for="l in items" :key="l.id" @click="select(l)" class="tel-row" style="align-items: flex-start" :class="{ 'tel-row--active': l.id === activeId }">
                                    <span class="tel-row__time">{{ time(l.timestamp) }}</span>
                                    <Badge :text="l.level" :color="levelColor(l.level)" size="sm" />
                                    <span class="tel-row__main font-mono text-xs">{{ l.line }}</span>
                                </button>
                            </template>
                        </div>
                    </SplitterPanel>

                    <SplitterResizeHandle class="w-px bg-gray-200 dark:bg-gray-700" />

                    <SplitterPanel :min-size="30">
                        <div class="h-full overflow-auto">
                            <div v-if="!selected" class="flex h-full items-center justify-center text-sm text-gray-500">Select an item to inspect it.</div>

                            <!-- trace waterfall -->
                            <div v-else-if="isTrace">
                                <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                                    <div class="truncate font-medium">{{ selected.name }}</div>
                                    <div class="mt-0.5 text-xs text-gray-500"><code class="font-mono">{{ selected.id }}</code> · {{ ms(selected.durationMs) }}</div>
                                </div>
                                <div v-if="detail && detail.spans && detail.spans.length"><Waterfall :spans="detail.spans" /></div>
                                <div v-else class="p-8 text-center text-sm text-gray-500">No spans — the trace is outside the backend's retention window, or hasn't sealed yet.</div>
                            </div>

                            <!-- log detail -->
                            <div v-else class="p-4">
                                <div class="mb-3 flex items-center gap-2">
                                    <Badge :text="selected.level" :color="levelColor(selected.level)" size="sm" />
                                    <span class="text-xs text-gray-500">{{ new Date(selected.timestamp).toLocaleString() }}</span>
                                </div>
                                <pre class="mb-4 whitespace-pre-wrap break-words rounded-lg bg-gray-500/10 p-3 font-mono text-xs">{{ selected.line }}</pre>
                                <div class="gap-x-3 gap-y-1 text-xs" style="display: grid; grid-template-columns: 10rem 1fr">
                                    <template v-for="(v, k) in selected.labels" :key="k">
                                        <div class="truncate text-gray-500">{{ k }}</div>
                                        <div class="break-words font-mono">{{ v }}</div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </SplitterPanel>
                </SplitterGroup>
            </Panel>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { Header, Panel, Badge, Button, SplitterGroup, SplitterPanel, SplitterResizeHandle } from '@statamic/cms/ui';
import { Head, router, usePoll } from '@statamic/cms/inertia';
import Waterfall from '../components/Waterfall.vue';
import TelemetryTabs from '../components/TelemetryTabs.vue';
import StatHeader from '../components/StatHeader.vue';
import TimeChart from '../components/TimeChart.vue';
import HeatChart from '../components/HeatChart.vue';
import { count as fmt, ms, percent as pct, clock as time } from '../lib/format.js';

const props = defineProps({
    signal: String,
    signals: Object,
    window: String,
    windows: Object,
    from: Number,
    to: Number,
    dashboardUrl: String,
    exploreUrl: String,
    q: String,
    filters: { type: Object, default: () => ({}) },
    selectedId: String,
    items: { type: Array, default: () => [] },
    detail: Object,
    summary: Object,
    facets: Object,
    throughput: Object,
    heatmap: Object,
    deployments: { type: Array, default: () => [] },
});

const searchText = ref(props.q || '');
const activeId = ref(props.selectedId || null);
const selectedLog = ref(null);
const tailing = ref(false);

const facetDims = ['status', 'method', 'route'];
const dimLabel = (d) => ({ status: 'Status', method: 'Method', route: 'Route' })[d] || d;

const isTrace = computed(() => props.signal === 'requests' || props.signal === 'traces');
const hasFilters = computed(() => Object.keys(props.filters || {}).length > 0);
const selected = computed(() => {
    if (!activeId.value) return null;
    if (isTrace.value) return props.items.find((i) => i.id === activeId.value) || null;
    return selectedLog.value;
});

const tone = (v) => (v >= 1000 ? 'var(--color-red-500)' : v >= 300 ? 'var(--color-orange-500)' : 'var(--color-green-500)');
const levelColor = (l) => ({ error: 'red', critical: 'red', alert: 'red', warning: 'orange', warn: 'orange', notice: 'blue', info: 'blue', debug: 'default' }[(l || '').toLowerCase()] || 'default');

const statItems = computed(() => {
    const s = props.summary;
    if (!s) return [];
    return [
        { label: 'Requests', value: fmt(s.requests) },
        { label: 'Error rate', value: pct(s.errorRate), tone: s.errorRate > 0 ? 'var(--color-red-500)' : undefined },
        { label: 'P50', value: ms(s.p50), tone: tone(s.p50) },
        { label: 'P95', value: ms(s.p95), tone: tone(s.p95) },
        { label: 'Traces', value: fmt(props.items.length) },
    ];
});

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
const bounds = computed(() => {
    if (props.to) return { start: props.from * 1000, end: props.to * 1000 };
    const m = /^(\d+)([mh])$/.exec(props.window || '1h');
    const secs = m ? +m[1] * (m[2] === 'h' ? 3600 : 60) : 3600;
    const end = Date.now();
    return { start: end - secs * 1000, end };
});
const chartMarkers = computed(() =>
    (props.deployments || [])
        .filter((d) => d.timestamp >= bounds.value.start && d.timestamp <= bounds.value.end)
        .map((d) => ({ time: d.timestamp, label: `${time(d.timestamp)} · ${d.notes || d.id}` })),
);
const rangeLabel = computed(() => (props.to ? `${time(props.from * 1000)}–${time(props.to * 1000)}` : ''));
const facetMax = (dim) => Math.max(1, ...(props.facets?.[dim] || []).map((f) => f.count));
const barPct = (dim, c) => Math.round((c / facetMax(dim)) * 100) + '%';

const go = (data, options = {}) => router.get(location.pathname, data, { preserveScroll: true, ...options });
// A brushed from/to range sticks across facet/search/select; a preset window is
// carried otherwise.
const base = () => {
    const b = { signal: props.signal, window: props.window, q: searchText.value, ...(props.filters || {}) };
    if (props.to) {
        b.from = props.from;
        b.to = props.to;
    }
    return b;
};

function changeSignal(s) { activeId.value = null; selectedLog.value = null; go({ signal: s, window: props.window, q: searchText.value }); }
function changeWindow(w) { go({ signal: props.signal, q: searchText.value, ...(props.filters || {}), window: w }); }
function applySearch() { go(base()); }

function onZoom([fromMs, toMs]) {
    go({ signal: props.signal, window: props.window, q: searchText.value, ...(props.filters || {}), from: Math.round(fromMs / 1000), to: Math.round(toMs / 1000) });
}
function resetRange() { go({ signal: props.signal, window: props.window, q: searchText.value, ...(props.filters || {}) }); }

function toggleFacet(dim, value) {
    const f = { ...(props.filters || {}) };
    if (f[dim] === String(value)) delete f[dim];
    else f[dim] = String(value);
    activeId.value = null;
    selectedLog.value = null;
    go({ signal: props.signal, window: props.window, q: searchText.value, ...f });
}
function clearFilters() { go({ signal: props.signal, window: props.window, q: searchText.value }); }

function select(item) {
    activeId.value = item.id;
    if (isTrace.value) {
        go({ ...base(), id: item.id });
    } else {
        selectedLog.value = item;
    }
}

const poll = usePoll(2000, { preserveScroll: true }, { autoStart: false });
function toggleTail() {
    tailing.value = !tailing.value;
    tailing.value ? poll.start() : poll.stop();
}

watch(() => props.signal, () => { searchText.value = props.q || ''; });
</script>
