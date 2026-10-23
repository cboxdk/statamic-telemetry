<template>
    <div class="py-2">
        <div v-for="(span, i) in laidOut" :key="i" class="grid items-center gap-3 px-4 py-1 text-sm hover:bg-gray-500/5" style="grid-template-columns: minmax(8rem, 18rem) 1fr 4.5rem">
            <div class="truncate" :style="{ paddingLeft: span.depth * 14 + 'px' }">{{ span.name }}</div>
            <div class="relative h-3.5 rounded bg-gray-500/10">
                <div class="absolute top-0 h-full rounded bg-blue-500" :style="{ left: span.offset + '%', width: span.width + '%' }"></div>
            </div>
            <div class="text-right tabular-nums text-gray-500">{{ ms(span.durationMs) }}</div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({ spans: { type: Array, default: () => [] } });

const bounds = computed(() => {
    if (!props.spans.length) return { min: 0, total: 1 };
    const starts = props.spans.map((s) => s.startNano);
    const min = Math.min(...starts);
    const maxEnd = Math.max(...props.spans.map((s) => s.startNano + s.durationMs * 1e6));
    return { min, total: Math.max(1, maxEnd - min) };
});

const laidOut = computed(() =>
    props.spans.map((s) => {
        const width = Math.max(0.4, ((s.durationMs * 1e6) / bounds.value.total) * 100);
        const offset = Math.min(((s.startNano - bounds.value.min) / bounds.value.total) * 100, 100 - width);
        return { name: s.name, depth: Math.min(s.depth, 10), durationMs: s.durationMs, width: r(width), offset: r(offset) };
    })
);

const ms = (v) => (v >= 1000 ? (v / 1000).toFixed(2) + ' s' : Math.round(v * 10) / 10 + ' ms');
const r = (n) => Math.round(n * 100) / 100;
</script>
