<template>
    <div ref="el" class="tel-timechart" :style="{ height: height + 'px' }"></div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
import * as echarts from 'echarts/core';
import { HeatmapChart } from 'echarts/charts';
import { GridComponent, TooltipComponent, VisualMapComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { count as fmtCount, clock } from '../lib/format.js';

echarts.use([HeatmapChart, GridComponent, TooltipComponent, VisualMapComponent, CanvasRenderer]);

const props = defineProps({
    cols: { type: Array, default: () => [] }, // ms timestamps per column
    bands: { type: Array, default: () => [] }, // row labels, fast → slow
    cells: { type: Array, default: () => [] }, // [colIdx, bandIdx, count]
    max: { type: Number, default: 0 },
    height: { type: Number, default: 150 },
});

const el = ref(null);
let chart = null;
let observer = null;
let probe = null;

function rgb(varName) {
    let c = getComputedStyle(document.documentElement).getPropertyValue(varName).trim() || '#3b82f6';
    if (!probe) probe = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
    probe.clearRect(0, 0, 1, 1);
    probe.fillStyle = c;
    probe.fillRect(0, 0, 1, 1);
    const [r, g, b] = probe.getImageData(0, 0, 1, 1).data;
    return [r, g, b];
}
const isDark = () => document.documentElement.classList.contains('dark') || !!el.value?.closest('.dark');

function buildOption() {
    const dark = isDark();
    const muted = dark ? 'rgba(255,255,255,0.45)' : 'rgba(0,0,0,0.45)';
    const [r, g, b] = rgb('--color-blue-500');
    const cardBorder = dark ? '#18181b' : '#ffffff';

    return {
        animation: false,
        grid: { left: 8, right: 8, top: 6, bottom: 6, containLabel: true },
        tooltip: {
            backgroundColor: dark ? '#1c1c22' : '#ffffff',
            borderColor: dark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.07)',
            borderWidth: 1,
            padding: [6, 10],
            textStyle: { color: dark ? '#e5e7eb' : '#18181b', fontSize: 12 },
            formatter: (p) =>
                `<div style="font-size:11px;opacity:.6">${clock(props.cols[p.data[0]])} · ${props.bands[p.data[1]]}</div><b>${fmtCount(p.data[2])}</b> requests`,
        },
        xAxis: {
            type: 'category',
            data: props.cols.map((c) => clock(c)),
            axisLabel: { color: muted, fontSize: 10, hideOverlap: true },
            axisTick: { show: false },
            axisLine: { show: false },
            splitArea: { show: false },
        },
        yAxis: {
            type: 'category',
            data: props.bands,
            axisLabel: { color: muted, fontSize: 10, fontFamily: 'ui-monospace, monospace' },
            axisTick: { show: false },
            axisLine: { show: false },
        },
        visualMap: {
            show: false,
            min: 0,
            max: Math.max(1, props.max),
            inRange: { color: [`rgba(${r},${g},${b},0.08)`, `rgba(${r},${g},${b},0.5)`, `rgba(${r},${g},${b},0.95)`] },
        },
        series: [
            {
                type: 'heatmap',
                data: props.cells,
                itemStyle: { borderRadius: 2, borderColor: cardBorder, borderWidth: 1 },
            },
        ],
    };
}

function render() {
    if (chart) chart.setOption(buildOption(), true);
}

onMounted(async () => {
    await nextTick();
    if (!el.value) return;
    chart = echarts.init(el.value, null, { renderer: 'canvas' });
    render();
    observer = new ResizeObserver(() => chart && chart.resize());
    observer.observe(el.value);
});

onBeforeUnmount(() => {
    observer && observer.disconnect();
    chart && chart.dispose();
    chart = null;
});

watch(() => [props.cells, props.cols, props.bands, props.max], render, { deep: true });
</script>
