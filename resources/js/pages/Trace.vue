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
            <Waterfall :spans="spans" />
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
import Waterfall from '../components/Waterfall.vue';

const props = defineProps({
    traceId: String,
    spans: { type: Array, default: () => [] },
});

const dashboardUrl = computed(() => location.pathname);

const totalMs = computed(() => {
    if (!props.spans.length) return 0;
    const min = Math.min(...props.spans.map((s) => s.startNano));
    const maxEnd = Math.max(...props.spans.map((s) => s.startNano + s.durationMs * 1e6));
    return Math.max(0, maxEnd - min) / 1e6;
});

const ms = (v) => (v >= 1000 ? (v / 1000).toFixed(2) + ' s' : Math.round(v * 10) / 10 + ' ms');
</script>
