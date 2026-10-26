<template>
    <div class="relative text-xs">
        <!-- Ruler -->
        <div class="sticky top-0 z-10 grid h-6 grid-cols-[minmax(0,46%)_minmax(0,1fr)] items-center border-b border-gray-200 bg-white text-[10px] text-gray-400 dark:border-gray-800 dark:bg-gray-900">
            <div class="px-3 uppercase tracking-wider">{{ __('Span') }}</div>
            <div class="relative h-full border-s border-gray-200 dark:border-gray-800">
                <span v-for="t in ticks" :key="t" class="absolute top-1 font-mono whitespace-nowrap" :class="t === 0 ? 'translate-x-1' : t === 100 ? '-translate-x-full' : '-translate-x-1/2'" :style="{ left: t + '%' }">{{ tickLabel(t) }}</span>
            </div>
        </div>

        <!-- Rows -->
        <div
            v-for="row in rows"
            :key="row.key"
            role="button"
            tabindex="0"
            class="grid min-h-7 cursor-pointer grid-cols-[minmax(0,46%)_minmax(0,1fr)] items-center border-b border-gray-100 transition-colors hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800"
            :class="{ 'bg-blue-500/10': selected === row.key }"
            @click="select(row)"
            @keydown.enter.prevent="select(row)"
        >
            <div class="flex min-w-0 items-center gap-1.5 pe-2" :style="{ paddingLeft: 6 + row.depth * 12 + 'px' }">
                <span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="row.error ? 'bg-red-500' : categoryDot(row.category)" />
                <!-- The name keeps its width; only the dim summary (SQL, route) gives way. -->
                <span class="max-w-[70%] shrink-0 truncate font-mono" :title="row.name">{{ row.name }}</span>
                <span v-if="row.summary" class="min-w-0 truncate font-mono text-gray-400" :title="row.summary">{{ row.summary }}</span>
            </div>
            <div class="relative h-7 border-s border-gray-200 bg-[linear-gradient(to_right,transparent_calc(25%-1px),var(--color-gray-200)_25%,transparent_calc(25%+1px),transparent_calc(50%-1px),var(--color-gray-200)_50%,transparent_calc(50%+1px),transparent_calc(75%-1px),var(--color-gray-200)_75%,transparent_calc(75%+1px))] dark:border-gray-800 dark:bg-[linear-gradient(to_right,transparent_calc(25%-1px),var(--color-gray-800)_25%,transparent_calc(25%+1px),transparent_calc(50%-1px),var(--color-gray-800)_50%,transparent_calc(50%+1px),transparent_calc(75%-1px),var(--color-gray-800)_75%,transparent_calc(75%+1px))]">
                <span
                    class="absolute top-[9px] h-2.5 min-w-[2px] rounded-[3px] opacity-90"
                    :class="row.error ? 'bg-red-500' : categoryBar(row.category)"
                    :style="{ left: row.offset + '%', width: row.width + '%' }"
                />
                <span
                    class="absolute top-1.5 whitespace-nowrap px-0.5 font-mono text-[10.5px] text-gray-500"
                    :class="{ 'text-white': row.labelInside }"
                    :style="row.labelStyle"
                >{{ ms(row.durationMs) }}</span>
            </div>
        </div>

        <!-- Selected span: a nested Stack, so the waterfall stays put and × / Esc comes straight back. -->
        <Stack :open="!!selectedRow" size="narrow" :title="selectedRow?.name || 'Span'" @update:open="(open) => !open && (selected = null)">
            <SpanDetail v-if="selectedRow" :span="selectedRow" />
        </Stack>
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { Stack } from '@statamic/cms/ui';
import SpanDetail from './SpanDetail.vue';
import { ms } from '../lib/format.js';

const props = defineProps({ spans: { type: Array, default: () => [] } });

const selected = ref(null);
const select = (row) => (selected.value = selected.value === row.key ? null : row.key);
watch(() => props.spans, () => (selected.value = null));

const ticks = [0, 25, 50, 75, 100];

const bounds = computed(() => {
    if (!props.spans.length) return { min: 0, total: 1 };
    const min = Math.min(...props.spans.map((s) => s.startNano));
    const maxEnd = Math.max(...props.spans.map((s) => s.startNano + s.durationMs * 1e6));
    return { min, total: Math.max(1, maxEnd - min) };
});
const tickLabel = (t) => (t === 0 ? '0' : ms((bounds.value.total / 1e6) * (t / 100)));

// telemetry-ui's categories, from the span's attributes.
function categoryOf(attrs = {}, kind) {
    if (attrs['db.system'] || attrs['db.query.text'] || attrs['db.statement']) return 'db';
    if (Object.keys(attrs).some((k) => k.startsWith('cache.'))) return 'cache';
    if (Object.keys(attrs).some((k) => k.startsWith('messaging.'))) return 'queue';
    if (attrs['view.name']) return 'view';
    if (kind === 'client' || Object.keys(attrs).some((k) => k.startsWith('http.client') || k === 'server.address')) return 'http';
    if (attrs['net.peer.name'] || attrs['network.peer.address']) return 'connect';
    return 'app';
}
const categoryBar = (c) => ({ db: 'bg-amber-500', cache: 'bg-purple-500', queue: 'bg-green-500', view: 'bg-teal-500', http: 'bg-cyan-500', connect: 'bg-red-400', app: 'bg-blue-500' }[c] || 'bg-blue-500');
const categoryDot = (c) => categoryBar(c);

const summaryOf = (attrs = {}) =>
    attrs['db.query.text'] || attrs['db.statement'] || attrs['http.route'] || attrs['url.path'] || attrs['view.name'] || attrs['messaging.destination.name'] || '';

const rows = computed(() =>
    props.spans.map((s, i) => {
        const attrs = s.attributes || {};
        const width = Math.max(0.3, ((s.durationMs * 1e6) / bounds.value.total) * 100);
        const offset = Math.min(((s.startNano - bounds.value.min) / bounds.value.total) * 100, 100 - width);
        const end = offset + width;
        // Label after the bar if room, before it if it starts late, else inside.
        let labelStyle, labelInside = false;
        if (end < 78) labelStyle = { left: `calc(${end}% + 4px)` };
        else if (offset > 18) labelStyle = { right: `calc(${100 - offset}% + 4px)` };
        else { labelStyle = { left: `${offset}%` }; labelInside = width > 8; }
        const status = Number(attrs['http.response.status_code']);
        return {
            key: `${i}-${s.startNano}`,
            name: s.name,
            // No summary when the name already says it ("GET entry:pages" + "entry:pages").
            summary: summaryOf(attrs) && !s.name.includes(summaryOf(attrs)) ? summaryOf(attrs) : '',
            depth: Math.min(s.depth || 0, 12),
            durationMs: s.durationMs,
            offset: r(offset),
            width: r(width),
            labelStyle,
            labelInside,
            category: categoryOf(attrs, s.kind),
            error: (typeof attrs.status === 'string' && attrs.status.startsWith('error')) || status >= 500,
            service: s.service,
            kind: s.kind,
            attributes: attrs,
            offsetMs: (s.startNano - bounds.value.min) / 1e6,
        };
    }),
);

const selectedRow = computed(() => rows.value.find((row) => row.key === selected.value) || null);
const r = (n) => Math.round(n * 100) / 100;
</script>
