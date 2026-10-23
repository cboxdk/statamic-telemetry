<template>
    <div>
        <Head title="Trace" />
        <Header title="Trace" icon="chart-monitoring-indicator">
            <Link :href="dashboardUrl" class="text-sm text-blue-500 no-underline hover:underline">← Back to Telemetry</Link>
        </Header>

        <p class="mb-4 text-sm text-gray-500">
            <code class="rounded bg-gray-500/10 px-1.5 py-0.5 font-mono">{{ traceId }}</code>
            · {{ ms(totalMs) }} · {{ spans.length }} spans
        </p>

        <Panel v-if="spans.length" heading="Waterfall">
            <div class="py-2">
                <div v-for="(span, i) in laidOut" :key="i" class="grid items-center gap-3 px-4 py-1 text-sm hover:bg-gray-500/5" style="grid-template-columns: minmax(10rem, 22rem) 1fr 5rem">
                    <div class="truncate" :style="{ paddingLeft: span.depth * 14 + 'px' }">{{ span.name }}</div>
                    <div class="relative h-3.5 rounded bg-gray-500/10">
                        <div class="absolute top-0 h-full rounded bg-blue-500" :style="{ left: span.offset + '%', width: span.width + '%' }"></div>
                    </div>
                    <div class="text-right tabular-nums text-gray-500">{{ ms(span.durationMs) }}</div>
                </div>
            </div>
        </Panel>

        <Panel v-else heading="Trace not found">
            <p class="p-6 text-center text-gray-500">This trace is no longer in the backend's retention window, or the backend is unreachable.</p>
        </Panel>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { Header, Panel } from '@statamic/cms/ui';
import { Head, Link } from '@statamic/cms/inertia';

const props = defineProps({
    traceId: String,
    spans: { type: Array, default: () => [] },
});

const dashboardUrl = computed(() => location.pathname);

const bounds = computed(() => {
    if (!props.spans.length) return { min: 0, total: 1 };
    const starts = props.spans.map((s) => s.startNano);
    const min = Math.min(...starts);
    const maxEnd = Math.max(...props.spans.map((s) => s.startNano + s.durationMs * 1e6));
    return { min, total: Math.max(1, maxEnd - min) };
});

const totalMs = computed(() => bounds.value.total / 1e6);

const laidOut = computed(() =>
    props.spans.map((s) => {
        const width = Math.max(0.4, ((s.durationMs * 1e6) / bounds.value.total) * 100);
        const offset = Math.min(((s.startNano - bounds.value.min) / bounds.value.total) * 100, 100 - width);
        return { name: s.name, depth: Math.min(s.depth, 10), durationMs: s.durationMs, width: round(width), offset: round(offset) };
    })
);

const ms = (v) => (v >= 1000 ? (v / 1000).toFixed(2) + ' s' : (Math.round(v * 10) / 10) + ' ms');
const round = (n) => Math.round(n * 100) / 100;
</script>
