<template>
    <svg
        v-if="hasData"
        :viewBox="`0 0 ${width} ${height}`"
        preserveAspectRatio="none"
        class="block h-full w-full"
        role="img"
        aria-hidden="true"
    >
        <!-- stacked bands (bottom-up) or overlaid areas/lines -->
        <g v-for="(band, i) in bands" :key="`b${i}`" :style="{ color: band.color }">
            <path v-if="band.area" :d="band.area" fill="currentColor" :fill-opacity="stacked ? 0.55 : 0.15" stroke="none" />
            <path v-if="band.line" :d="band.line" fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke" />
        </g>

        <!-- deploy markers -->
        <g v-for="(m, i) in markerLines" :key="`m${i}`">
            <line :x1="m.x" :y1="0" :x2="m.x" :y2="height" stroke="var(--color-gray-500)" stroke-width="1" stroke-dasharray="3 3" vector-effect="non-scaling-stroke" opacity="0.7" />
        </g>
    </svg>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    // Convenience single series.
    points: { type: Array, default: () => [] },
    // Multiple series: [{ values: number[], color, area?: bool }]. Takes precedence over points.
    series: { type: Array, default: () => [] },
    // Stack the series instead of overlaying them.
    stacked: { type: Boolean, default: false },
    // Vertical markers: [{ x: 0..1 fraction of the width }].
    markers: { type: Array, default: () => [] },
    width: { type: Number, default: 640 },
    height: { type: Number, default: 120 },
    color: { type: String, default: 'var(--color-blue-500)' },
});

const pad = 4;

// Normalise into a series list of { values[], color, area }.
const resolved = computed(() => {
    if (props.series.length) {
        return props.series.map((s) => ({
            values: (s.values || []).map(Number),
            color: s.color || props.color,
            area: s.area !== false,
        }));
    }
    if (props.points.length) {
        return [{ values: props.points.map(Number), color: props.color, area: true }];
    }
    return [];
});

const length = computed(() => Math.max(0, ...resolved.value.map((s) => s.values.length)));
const hasData = computed(() => resolved.value.some((s) => s.values.some((v) => v > 0)) || (resolved.value.length > 0 && length.value > 1));

// Peak used for the y scale: stacked → max column sum; overlaid → max single value.
const max = computed(() => {
    if (props.stacked) {
        let peak = 1;
        for (let i = 0; i < length.value; i++) {
            let sum = 0;
            for (const s of resolved.value) sum += s.values[i] || 0;
            if (sum > peak) peak = sum;
        }
        return peak;
    }
    return Math.max(1, ...resolved.value.flatMap((s) => s.values));
});

const xAt = (i) => Math.round((i * (props.width / Math.max(1, length.value - 1))) * 100) / 100;
const yAt = (v) => {
    const usable = props.height - pad * 2;
    return Math.round((pad + usable - (v / max.value) * usable) * 100) / 100;
};

const bands = computed(() => {
    const out = [];
    const cum = new Array(length.value).fill(0);

    for (const s of resolved.value) {
        const vals = s.values.length === 1 ? [s.values[0], s.values[0]] : s.values;
        const n = Math.max(2, vals.length);

        if (props.stacked) {
            // Fill between the previous cumulative and the new one.
            const lower = cum.slice(0, n);
            const upper = lower.map((base, i) => base + (vals[i] || 0));
            for (let i = 0; i < n; i++) cum[i] = upper[i];

            const top = upper.map((v, i) => `${xAt(i)},${yAt(v)}`);
            const bottom = lower.map((v, i) => `${xAt(i)},${yAt(v)}`).reverse();
            out.push({ color: s.color, area: `M${top.join(' L')} L${bottom.join(' L')} Z`, line: null });
        } else {
            const coords = vals.map((v, i) => [xAt(i), yAt(v)]);
            const line = 'M' + coords.map((c) => c.join(',')).join(' L');
            out.push({
                color: s.color,
                area: s.area ? `${line} L${props.width},${props.height} L0,${props.height} Z` : null,
                line,
            });
        }
    }

    return out;
});

const markerLines = computed(() =>
    props.markers
        .map((m) => ({ x: Math.round(Math.min(1, Math.max(0, Number(m.x))) * props.width * 100) / 100 }))
        .filter((m) => !Number.isNaN(m.x)),
);
</script>
