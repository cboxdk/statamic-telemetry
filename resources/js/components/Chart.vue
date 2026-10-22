<template>
    <svg
        v-if="points.length"
        :viewBox="`0 0 ${width} ${height}`"
        preserveAspectRatio="none"
        class="block h-full w-full"
        :style="{ color }"
        role="img"
        aria-hidden="true"
    >
        <path :d="areaPath" fill="currentColor" fill-opacity="0.15" stroke="none" />
        <path :d="linePath" fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke" />
    </svg>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    points: { type: Array, default: () => [] },
    width: { type: Number, default: 640 },
    height: { type: Number, default: 120 },
    color: { type: String, default: 'var(--color-blue-500)' },
});

const coords = computed(() => {
    const pts = props.points.map(Number);
    if (pts.length === 1) pts.push(pts[0]);
    const max = Math.max(1, ...pts);
    const pad = 4;
    const usable = props.height - pad * 2;
    const step = props.width / Math.max(1, pts.length - 1);
    return pts.map((p, i) => [
        Math.round(i * step * 100) / 100,
        Math.round((pad + usable - (p / max) * usable) * 100) / 100,
    ]);
});

const linePath = computed(() => 'M' + coords.value.map((c) => c.join(',')).join(' L'));
const areaPath = computed(() => `${linePath.value} L${props.width},${props.height} L0,${props.height} Z`);
</script>
