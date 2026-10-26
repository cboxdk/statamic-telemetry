<template>
    <!-- The entry's Telemetry tab: its own story, full width — traffic and latency against the site,
         how the static cache treats it, exceptions on its path, who changed it — with every request
         openable as its trace, right here in the editor. -->
    <div class="space-y-6 py-2">
        <div v-if="!meta.available" class="rounded-xl border border-dashed border-gray-300 p-6 text-sm text-gray-500 dark:border-gray-700">
            {{ __('Telemetry for this entry appears once it has been saved and requested.') }}
        </div>

        <template v-else>
            <!-- Toolbar -->
            <div class="flex flex-wrap items-center gap-3">
                <ToggleGroup :model-value="window" size="sm" @update:model-value="setWindow">
                    <ToggleItem v-for="(label, w) in meta.windows" :key="w" :value="w" :label="w" v-tooltip="label" />
                </ToggleGroup>
                <span v-if="story" class="text-xs text-gray-500">{{ rangeText }}</span>
                <div class="ms-auto flex items-center gap-2">
                    <Button size="sm" :href="requestsHref" :text="__('Open in Telemetry')" />
                    <Button v-if="meta.entry.url" size="sm" variant="ghost" :href="meta.entry.url" target="_blank" :text="__('View page')" />
                </div>
            </div>

            <div v-if="error" class="rounded-xl border border-red-200 bg-red-500/5 p-4 text-sm text-red-600 dark:border-red-900 dark:text-red-400">{{ error }}</div>

            <div v-else-if="!story" class="grid gap-6 lg:grid-cols-2">
                <Card v-for="i in 2" :key="i" class="space-y-3"><Skeleton class="h-4 w-1/3" /><Skeleton class="h-10 w-2/3" /><Skeleton class="h-40 w-full" /></Card>
            </div>

            <template v-else>
                <!-- Traffic + latency -->
                <div class="grid gap-6 2xl:grid-cols-2">
                    <Section :heading="__('Traffic')">
                        <template #actions>{{ __('requests to this page') }}</template>
                        <div class="grid grid-cols-[repeat(auto-fit,minmax(5.5rem,1fr))] gap-3">
                            <Tile :label="__('Requests')" :value="fmt(req.count)" :delta="deltaText(req.count, req.previous)" :tone="deltaTone(req.count, req.previous)" />
                            <Tile :label="__('2XX')" :value="fmt(req.statusMix['2xx'])" dim />
                            <Tile :label="__('3XX')" :value="fmt(req.statusMix['3xx'])" dim />
                            <Tile :label="__('4XX')" :value="fmt(req.statusMix['4xx'])" :tone="req.statusMix['4xx'] > 0 ? 'text-amber-600 dark:text-amber-400' : ''" />
                            <Tile :label="__('5XX')" :value="fmt(req.statusMix['5xx'])" :tone="req.statusMix['5xx'] > 0 ? 'text-red-500' : ''" />
                            <Tile :label="__('Error rate')" :value="pct(req.errorRate)" :tone="req.errorRate > 0.01 ? 'text-red-500' : ''" />
                        </div>
                        <div class="h-44">
                            <TimeChart v-if="req.count" :time="seriesTime" :series="trafficSeries" unit="count" :smooth="false" :height="176" :legend="true" />
                            <Empty v-else>{{ __('No requests to this page in the :window.', { window: meta.windows[window].toLowerCase() }) }}</Empty>
                        </div>
                    </Section>

                    <Section :heading="__('Latency')">
                        <template #actions>{{ __('server-side, this page vs the site') }}</template>
                        <div class="grid grid-cols-[repeat(auto-fit,minmax(5.5rem,1fr))] gap-3">
                            <Tile :label="__('Avg')" :value="ms(req.avg)" />
                            <Tile label="p50" :value="ms(req.p50)" dim />
                            <Tile label="p95" :value="ms(req.p95)" :delta="deltaText(req.p95, req.previousP95, true)" :tone="deltaTone(req.p95, req.previousP95, true)" />
                            <Tile :label="__('Site p95')" :value="req.siteP95 ? ms(req.siteP95) : '—'" :sub="vsSite" :tone="vsSiteTone" />
                        </div>
                        <div class="h-44">
                            <TimeChart v-if="req.count" :time="seriesTime" :series="latencySeries" unit="ms" :height="176" :legend="false" />
                            <Empty v-else>{{ __('No latency to show.') }}</Empty>
                        </div>
                    </Section>
                </div>

                <!-- Cache + exceptions -->
                <div class="grid gap-6 2xl:grid-cols-2">
                    <Section :heading="__('Static cache')">
                        <template #actions>{{ __('how this page was served') }}</template>
                        <div class="grid grid-cols-[repeat(auto-fit,minmax(5.5rem,1fr))] gap-3">
                            <Tile :label="__('Hit ratio')" :value="story.cache.ratio == null ? '—' : Math.round(story.cache.ratio * 100) + '%'" :tone="story.cache.ratio != null && story.cache.ratio < 0.5 ? 'text-amber-600 dark:text-amber-400' : ''" :sub="story.cache.ratio == null ? __('not served from cache') : ''" />
                            <Tile :label="__('Hits')" :value="fmt(story.cache.hit)" :tone="story.cache.hit ? 'text-green-600 dark:text-green-400' : ''" />
                            <Tile :label="__('Misses')" :value="fmt(story.cache.miss)" />
                            <Tile :label="__('Writes')" :value="fmt(story.cache.write)" :sub="story.cache.lastWrite ? __('last :when', { when: dateClock(story.cache.lastWrite) }) : ''" dim />
                        </div>
                        <p class="text-xs text-gray-500">{{ __('A write follows a save or an invalidation; a run of misses means the cache is not holding this page.') }}</p>
                    </Section>

                    <Section :heading="__('Exceptions on this page')" inset>
                        <template #actions>{{ story.exceptions.count ? __(':count in the window', { count: fmt(story.exceptions.count) }) : __('none in the window') }}</template>
                        <div v-if="!story.exceptions.recent.length" class="px-4 pb-6 text-sm text-gray-500 sm:px-4.5">{{ __('No exceptions were logged against :path.', { path: meta.entry.uri }) }}</div>
                        <div v-else class="max-h-64 overflow-y-auto">
                            <div v-for="(e, i) in story.exceptions.recent" :key="i" class="grid grid-cols-[4.5rem_minmax(0,1fr)] items-start gap-3 border-t border-gray-100 px-4 py-2 text-sm first:border-0 dark:border-gray-800 sm:px-4.5">
                                <span class="pt-0.5 tabular-nums text-xs text-gray-500">{{ clock(e.timestamp) }}</span>
                                <span class="min-w-0">
                                    <span class="flex items-center gap-2">
                                        <span class="shrink-0 rounded bg-red-500/15 px-1 font-mono text-[10.5px] font-semibold text-red-600 dark:text-red-400">{{ e.type }}</span>
                                        <span class="truncate font-mono text-[13px]">{{ e.message || e.type }}</span>
                                    </span>
                                    <span v-if="e.origin" class="block truncate font-mono text-[11px] text-gray-500">{{ e.origin }}</span>
                                </span>
                            </div>
                        </div>
                    </Section>
                </div>

                <!-- Rows -->
                <div class="grid gap-6 2xl:grid-cols-2">
                    <Section :heading="__('Recent requests')" inset>
                        <template #actions>{{ __('click a row to open its trace') }}</template>
                        <div v-if="!req.recent.length" class="px-4 pb-6 text-sm text-gray-500 sm:px-4.5">{{ __('Nothing yet.') }}</div>
                        <div v-else class="max-h-96 overflow-y-auto">
                            <!-- No "Edit" here: these rows are this entry, and we are already editing it. -->
                            <RequestRow v-for="t in req.recent" :key="t.id" :row="{ ...t, editUrl: null }" :active="openTraceId === t.id" @open="openTrace" />
                        </div>
                        <div v-if="req.capped" class="border-t border-gray-200 bg-gray-500/5 px-4 py-2 text-xs text-gray-500 dark:border-gray-800">{{ __('Newest :count of many — narrow the window to see further back.', { count: fmt(req.recent.length) }) }}</div>
                    </Section>

                    <Section :heading="__('Change history')" inset>
                        <template #actions>{{ story.changes.count ? __n(':count change|:count changes', story.changes.count, { count: fmt(story.changes.count) }) : __('no changes in the window') }}</template>
                        <AuditRows v-if="story.changes.rows.length" :result="auditResult" />
                        <div v-else class="px-4 pb-6 text-sm text-gray-500 sm:px-4.5">{{ __('No changes to this entry in the window.') }}</div>
                    </Section>
                </div>
            </template>

            <DrawerHost />
        </template>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { Fieldtype } from '@statamic/cms';
import { Button, Card, Skeleton, ToggleGroup, ToggleItem } from '@statamic/cms/ui';
import Section from '../components/Section.vue';
import Tile from '../components/Tile.vue';
import Empty from '../components/Empty.vue';
import TimeChart from '../components/TimeChart.vue';
import RequestRow from '../components/RequestRow.vue';
import AuditRows from '../components/AuditRows.vue';
import DrawerHost from '../components/DrawerHost.vue';
import { useTelemetryUrl, rangeBounds } from '../lib/useTelemetryUrl.js';
import { telemetryUrl } from '../lib/cp.js';
import { count as fmt, ms, percent as pct, clock, dateClock, timeLabel } from '../lib/format.js';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
Fieldtype.use(emit, props);

const meta = computed(() => props.meta || { available: false });

// ── Window + fetch ─────────────────────────────────────────────────────────────
const WINDOW_KEY = 'statamic-telemetry.entry-window';
const window = ref((() => { try { return localStorage.getItem(WINDOW_KEY) || meta.value.default || '7d'; } catch { return '7d'; } })());
const story = ref(null);
const error = ref(null);
let inflight = 0;

async function load() {
    const n = ++inflight;
    story.value = null;
    error.value = null;
    try {
        const res = await fetch(`${meta.value.endpoint}?window=${encodeURIComponent(window.value)}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (n !== inflight) return;
        if (res.status === 403) { error.value = __('You do not have permission to view telemetry.'); return; }
        if (!res.ok) { error.value = __('Could not load telemetry (HTTP :status).', { status: res.status }); return; }
        story.value = await res.json();
    } catch (e) {
        if (n === inflight) error.value = __('Could not reach the telemetry backend.');
    }
}
function setWindow(w) {
    if (!w || w === window.value) return;
    window.value = w;
    try { localStorage.setItem(WINDOW_KEY, w); } catch {}
    load();
}
onMounted(() => meta.value.available && load());
watch(() => meta.value.entry?.id, () => meta.value.available && load());

const req = computed(() => story.value?.requests || { count: 0, previous: 0, statusMix: {}, recent: [], series: { t: [] } });
const rangeText = computed(() => {
    if (!story.value) return '';
    const [from, to] = rangeBounds({ window: story.value.window, from: story.value.from, to: story.value.to });
    return `${timeLabel(from, 2e9)} – ${timeLabel(to, 2e9)}`;
});
const requestsHref = computed(() => telemetryUrl('requests', { window: window.value, path: meta.value.entry?.uri }));

// ── Drawer (same mechanism as the telemetry pages; the editor URL carries ?drawer=) ──
const { replaceDrawer, topDrawer } = useTelemetryUrl();
const openTrace = (id) => replaceDrawer({ kind: 'trace', id }, { only: [] });
const openTraceId = computed(() => (topDrawer.value?.kind === 'trace' ? topDrawer.value.id : null));

// ── Charts ─────────────────────────────────────────────────────────────────────
const seriesTime = computed(() => (req.value.series?.t || []).map((t) => t * 1000));
const trafficSeries = computed(() => [
    { name: '2/3XX', data: req.value.series?.ok || [], color: 'var(--color-gray-400)', type: 'bar', stack: 'a' },
    { name: '4XX', data: req.value.series?.client || [], color: 'var(--color-amber-500)', type: 'bar', stack: 'a' },
    { name: '5XX', data: req.value.series?.server || [], color: 'var(--color-red-500)', type: 'bar', stack: 'a' },
]);
const latencySeries = computed(() => [{ name: 'p95', data: req.value.series?.p95 || [], color: 'var(--color-amber-500)' }]);

// ── Deltas and the site comparison ─────────────────────────────────────────────
const change = (now, before) => (before > 0 ? (now - before) / before : null);
const deltaText = (now, before, lowerIsBetter = false) => {
    const c = change(now, before);
    if (c === null || Math.abs(c) < 0.01) return '';
    return __(':change vs previous', { change: `${c > 0 ? '+' : '−'}${Math.round(Math.abs(c) * 100)}%` });
};
const deltaTone = (now, before, lowerIsBetter = false) => {
    const c = change(now, before);
    if (c === null || Math.abs(c) < 0.01) return '';
    const worse = lowerIsBetter ? c > 0 : false;
    return worse ? 'text-red-500' : lowerIsBetter ? 'text-green-600 dark:text-green-400' : '';
};
const vsSite = computed(() => {
    const r = req.value;
    if (!r.siteP95 || !r.p95) return '';
    const ratio = r.p95 / r.siteP95;
    return ratio >= 1.15 ? __('this page is :factor× slower', { factor: ratio.toFixed(1) }) : ratio <= 0.85 ? __('this page is :factor× faster', { factor: (1 / ratio).toFixed(1) }) : __('about the site average');
});
const vsSiteTone = computed(() => (req.value.siteP95 && req.value.p95 / req.value.siteP95 >= 1.15 ? 'text-red-500' : ''));

// ── Change history, in the audit table's shape ─────────────────────────────────
const auditResult = computed(() => ({ rows: story.value?.changes?.rows || [], total: story.value?.changes?.count || 0, page: 1, perPage: 1000, capped: false, error: null }));
</script>
