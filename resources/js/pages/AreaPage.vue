<template>
    <TelemetryPage :title="page.title" :icon="page.icon" :nav="nav" :window="window" :windows="windows" :from="from" :to="to" :carry="carry">
        <p class="-mt-2 text-sm text-gray-500">{{ page.description }}</p>

        <!-- Aggregate: tiles over a time chart -->
        <Card class="space-y-4">
            <div v-if="aggregate?.stats?.length" class="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-3">
                <Tile v-for="s in aggregate.stats" :key="s.label" :label="s.label" :value="s.value" :sub="s.sub || ''" :tone="s.tone || ''" :dim="!!s.dim" />
            </div>
            <div class="h-52">
                <TimeChart v-if="hasChart" :time="chartTime" :series="chartSeries" unit="count" :height="208" brush @zoom="onZoom" />
                <Empty v-else>No {{ page.title.toLowerCase() }} activity in this window.</Empty>
            </div>
        </Card>

        <!-- Breakdown table -->
        <Section v-if="breakdown?.rows?.length" :heading="breakdown.heading" :subheading="breakdown.subheading || ''" inset>
            <div class="px-4 pb-2 sm:px-4.5"><BreakdownTable :table="breakdown" /></div>
        </Section>

        <!-- Rows: what the numbers count -->
        <Card inset>
            <div class="flex flex-wrap items-center gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                <div class="me-auto">
                    <h3 class="text-sm font-semibold">{{ rowsHeading }}</h3>
                    <p class="text-xs text-gray-500">{{ rowsSub }}</p>
                </div>
                <div class="w-72 shrink-0"><Input v-model="searchText" type="search" :placeholder="searchPlaceholder" @keyup.enter="applySearch" /></div>
                <Button text="Search" @click="applySearch" />
            </div>

            <!-- Facets as chips -->
            <div v-if="facetDims.length" class="flex flex-wrap items-center gap-x-5 gap-y-2 border-b border-gray-200 px-4 py-2.5 text-xs dark:border-gray-800">
                <div v-for="dim in facetDims" :key="dim" class="flex flex-wrap items-center gap-1">
                    <span class="me-1 text-[10.5px] font-semibold uppercase tracking-wider text-gray-500">{{ rows.facetLabels[dim] }}</span>
                    <button
                        v-for="f in rows.facets[dim]"
                        :key="f.value"
                        type="button"
                        class="rounded-md border px-1.5 py-0.5 font-mono transition-colors"
                        :class="filters[dim] === String(f.value) ? 'border-blue-500 bg-blue-500/10' : 'border-gray-200 hover:bg-gray-500/10 dark:border-gray-700'"
                        @click="toggleFacet(dim, f.value)"
                    >{{ f.value }}<span v-if="f.count != null" class="ms-1 text-gray-400">{{ fmt(f.count) }}</span></button>
                </div>
                <Button v-if="hasFilters" size="2xs" variant="ghost" text="Clear" @click="clearFilters" />
            </div>

            <!-- Audit trail -->
            <template v-if="rows.kind === 'audit'">
                <div v-if="!rows.available" class="p-10 text-center text-sm text-gray-500">{{ rows.reason }}</div>
                <AuditRows v-else :result="rows.result" :filtered="hasFilters || !!q" @page="(p) => go({ page: p })" />
            </template>

            <!-- Requests -->
            <div v-else-if="rows.kind === 'requests'" class="max-h-[70vh] overflow-y-auto" scroll-region>
                <div v-if="!rows.items.length" class="p-10 text-center text-sm text-gray-500">Nothing in this window.</div>
                <RequestRow v-for="t in rows.items" :key="t.id" :row="t" :active="openTraceId === t.id" @open="openTrace" />
            </div>

            <!-- Log records (exceptions): type · message · origin, expandable to every label -->
            <div v-else-if="rows.kind === 'logs'" class="max-h-[70vh] overflow-y-auto" scroll-region>
                <div v-if="!rows.items.length" class="p-10 text-center text-sm text-gray-500">Nothing in this window.</div>
                <div v-for="r in rows.items" :key="r.id" class="border-b border-gray-100 dark:border-gray-800">
                    <button type="button" class="grid w-full grid-cols-[4.5rem_minmax(0,1fr)] items-start gap-3 px-4 py-2 text-left text-sm transition-colors hover:bg-gray-500/10" @click="toggleLog(r.id)">
                        <span class="pt-0.5 tabular-nums text-xs text-gray-500">{{ clock(r.timestamp) }}</span>
                        <span class="min-w-0">
                            <span class="flex items-center gap-2">
                                <span class="shrink-0 rounded bg-red-500/15 px-1 font-mono text-[10.5px] font-semibold text-red-600 dark:text-red-400" :title="r.fullType">{{ r.type }}</span>
                                <span class="min-w-0 font-mono text-[13px]" :class="expandedLog === r.id ? 'whitespace-pre-wrap break-words' : 'truncate'">{{ r.message || r.fullType }}</span>
                            </span>
                            <span class="flex min-w-0 items-center gap-2 text-[11px] text-gray-500">
                                <span v-if="r.origin" class="truncate font-mono">{{ r.origin }}</span>
                                <span v-if="r.path" class="truncate font-mono">{{ r.path }}</span>
                                <span v-else-if="r.route" class="truncate font-mono">{{ r.route }}</span>
                                <span v-if="r.group" class="shrink-0">group {{ r.group }}</span>
                            </span>
                        </span>
                    </button>
                    <div v-if="expandedLog === r.id" class="grid grid-cols-[12rem_1fr] gap-x-3 gap-y-1 bg-gray-500/5 px-4 py-3 text-xs">
                        <template v-for="(v, k) in r.labels" :key="k">
                            <div class="truncate text-gray-500">{{ k }}</div>
                            <div class="break-words font-mono">{{ v }}</div>
                        </template>
                        <div v-if="r.traceId" class="col-span-2 pt-1"><Button size="xs" text="Open trace" @click="openTrace(r.traceId)" /></div>
                    </div>
                </div>
            </div>

            <!-- Spans -->
            <div v-else class="max-h-[70vh] overflow-y-auto" scroll-region>
                <div v-if="!rows.items.length" class="p-10 text-center text-sm text-gray-500">Nothing in this window.</div>
                <button
                    v-for="r in rows.items"
                    :key="r.id"
                    type="button"
                    class="grid h-13 w-full grid-cols-[4.5rem_minmax(0,1fr)_auto] items-center gap-3 border-b border-gray-100 px-4 text-left text-sm transition-colors hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800"
                    :class="{ 'bg-blue-500/10': openTraceId === r.traceId }"
                    @click="openTrace(r.traceId)"
                >
                    <span class="tabular-nums text-xs text-gray-500">{{ clock(r.timestamp) }}</span>
                    <span class="min-w-0">
                        <span class="flex items-center gap-2">
                            <span class="truncate font-mono text-[13px]">{{ r.label }}</span>
                            <span v-for="chip in chips(r)" :key="chip.text" class="shrink-0 rounded px-1 font-mono text-[10.5px]" :class="chipClass(chip.tone)">{{ chip.text }}</span>
                        </span>
                        <span class="block truncate text-[11px] text-gray-500"><template v-if="r.request">in <span class="font-mono">{{ r.request }}</span></template><span v-else class="font-mono">{{ r.span }}</span></span>
                    </span>
                    <span class="tabular-nums text-gray-500">{{ r.durationMs > 0 ? ms(r.durationMs) : '' }}</span>
                </button>
            </div>

            <div v-if="rows.capped" class="border-t border-gray-200 bg-gray-500/5 px-4 py-2.5 text-xs text-gray-500 dark:border-gray-800">Showing the newest {{ fmt(rows.total) }} in this window — narrow the window to see further back.</div>
        </Card>
    </TelemetryPage>
</template>

<script setup>
import { ref, computed, watch, defineComponent, h } from 'vue';
import { Button, Card, Input, Table, TableColumns, TableColumn, TableRows, TableRow, TableCell } from '@statamic/cms/ui';
import TelemetryPage from '../components/TelemetryPage.vue';
import Section from '../components/Section.vue';
import Tile from '../components/Tile.vue';
import Empty from '../components/Empty.vue';
import TimeChart from '../components/TimeChart.vue';
import RequestRow from '../components/RequestRow.vue';
import AuditRows from '../components/AuditRows.vue';
import { useTelemetryUrl } from '../lib/useTelemetryUrl.js';
import { count as fmt, ms, clock, percent } from '../lib/format.js';

const props = defineProps({
    page: { type: Object, required: true },
    nav: { type: Array, default: () => [] },
    window: String,
    windows: Object,
    from: Number,
    to: Number,
    exploreUrl: String,
    q: { type: String, default: '' },
    filters: { type: Object, default: () => ({}) },
    aggregate: Object,
    rows: { type: Object, default: () => ({ kind: 'spans', items: [], facets: {}, facetLabels: {} }) },
});

// ── URL / state ────────────────────────────────────────────────────────────────
const searchText = ref(props.q || '');
watch(() => props.q, (q) => (searchText.value = q || ''));

const carry = () => ({ window: props.window, from: props.from, to: props.to, q: searchText.value, ...(props.filters || {}) });
const { go: navigate, replaceDrawer, topDrawer } = useTelemetryUrl(carry);
// Searching / filtering only reloads the rows; the aggregate is window-bound.
const go = (extra) => navigate(extra, { only: ['rows', 'filters', 'q'], preserveState: true });

const facetDims = computed(() => Object.keys(props.rows.facetLabels || {}).filter((d) => (props.rows.facets?.[d] || []).length));
const hasFilters = computed(() => Object.keys(props.filters || {}).length > 0);
const applySearch = () => go({ q: searchText.value || null, page: null });
const toggleFacet = (dim, value) => go({ [dim]: props.filters[dim] === String(value) ? null : String(value), page: null });
const clearFilters = () => go({ ...Object.fromEntries(Object.keys(props.rows.facetLabels || {}).map((k) => [k, null])), page: null });
const onZoom = ([fromMs, toMs]) => navigate({ from: Math.round(fromMs / 1000), to: Math.round(toMs / 1000) });

const openTrace = (id) => replaceDrawer({ kind: 'trace', id }, { only: [] });
const openTraceId = computed(() => (topDrawer.value?.kind === 'trace' ? topDrawer.value.id : null));

// ── Aggregate ──────────────────────────────────────────────────────────────────
const chartTime = computed(() => (props.aggregate?.chart?.t || []).map((t) => t * 1000));
const chartSeries = computed(() => (props.aggregate?.chart?.series || []).map((s) => ({ ...s, color: s.color || undefined })));
const hasChart = computed(() => chartSeries.value.some((s) => (s.data || []).some((v) => v > 0)));
const breakdown = computed(() => props.aggregate?.breakdown || null);

// ── Rows ───────────────────────────────────────────────────────────────────────
const HEADINGS = { requests: 'Requests', 'static-cache': 'Requests', jobs: 'Job runs', queries: 'Queries', exceptions: 'Exceptions', outgoing: 'Outgoing requests', stache: 'Warms and clears', glide: 'Generations', forms: 'Submissions', content: 'Changes' };
const rowsHeading = computed(() => HEADINGS[props.page.key] || 'Rows');
const rowsSub = computed(() => {
    if (props.rows.kind === 'audit') return 'Who changed what — click an item to edit it';
    if (props.rows.kind === 'logs') return `${fmt(props.rows.items?.length ?? 0)} of ${fmt(props.rows.total ?? 0)} in this window — click a record for its details`;
    const n = props.rows.items?.length ?? 0;
    return `${fmt(n)} of ${fmt(props.rows.total ?? n)} in this window — click a row to open its trace`;
});
const searchPlaceholder = computed(() => ({
    requests: 'Path, title, route, collection…', 'static-cache': 'Path, title, collection…', jobs: 'Job class, queue…', queries: 'SQL, table, connection…',
    exceptions: 'Exception, message, file…', outgoing: 'Host, URL, status…', stache: 'Request…', glide: 'Image path, preset, request…', forms: 'Form, request…', content: 'Title, id, editor, field…',
})[props.page.key] || 'Search…');
// The small facts after a span row's label; a failure is always the first, in red.
const chips = (r) => [
    ...(r.outcome === 'failed' || (r.error && r.error !== 'ok') ? [{ text: r.outcome === 'failed' ? 'failed' : String(r.error).slice(0, 40), tone: 'red' }] : []),
    ...[r.op, r.preset, r.container, r.form, r.queue, r.attempts ? `attempt ${r.attempts}` : null, r.connection, r.repeats > 1 ? `×${r.repeats}` : null, r.host, r.status]
        .filter(Boolean).map((text) => ({ text: String(text), tone: r.status >= 500 && String(text) === String(r.status) ? 'red' : r.status >= 400 && String(text) === String(r.status) ? 'amber' : 'default' })),
];
const chipClass = (tone) => ({ red: 'bg-red-500/15 text-red-600 dark:text-red-400', amber: 'bg-amber-500/15 text-amber-700 dark:text-amber-400' }[tone] || 'bg-gray-500/10 text-gray-500');
const expandedLog = ref(null);
const toggleLog = (id) => (expandedLog.value = expandedLog.value === id ? null : id);

// A breakdown table from the server's column spec: mono, right-aligned numbers, share bars.
const BreakdownTable = defineComponent({
    props: { table: { type: Object, required: true } },
    setup(p) {
        const cell = (col, row) => {
            const v = row[col.key];
            if (col.kind === 'share') {
                return h('div', { class: 'flex items-center gap-2' }, [
                    h('div', { class: 'h-1.5 w-24 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-800' }, [h('div', { class: 'h-full rounded-full bg-blue-500', style: { width: Math.round((v || 0) * 100) + '%' } })]),
                    h('span', { class: 'text-xs tabular-nums text-gray-500' }, percent(v || 0)),
                ]);
            }
            if (col.kind === 'ms') return h('span', { class: v >= 1000 ? 'text-red-500' : v >= 300 ? 'text-orange-500' : '' }, ms(v || 0));
            if (col.kind === 'errors') return h('span', { class: v > 0 ? 'text-red-500' : 'text-gray-500' }, fmt(v || 0));
            return typeof v === 'number' ? fmt(v) : (v ?? '—');
        };
        return () => h(Table, null, () => [
            h(TableColumns, null, () => p.table.columns.map((c) => h(TableColumn, { key: c.key, class: c.align === 'end' ? 'text-end' : '' }, () => c.label))),
            h(TableRows, null, () => p.table.rows.map((row, i) => h(TableRow, { key: i }, () => p.table.columns.map((c) =>
                h(TableCell, { key: c.key, class: [c.align === 'end' ? 'text-end tabular-nums' : '', c.kind === 'mono' ? 'font-mono text-xs' : ''] }, () => cell(c, row)),
            )))),
        ]);
    },
});
</script>
