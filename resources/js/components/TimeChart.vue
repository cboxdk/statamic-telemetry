<template>
    <div class="tel-timechart-wrap" :style="{ height: height + 'px' }">
        <div ref="el" class="tel-timechart"></div>
        <div class="tel-annot-layer">
            <div
                v-for="(f, i) in flags"
                :key="i"
                class="tel-annot"
                :style="{ left: f.x + 'px' }"
                :title="f.label"
            >
                <span class="tel-annot__flag"></span>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
import * as echarts from 'echarts/core';
import { LineChart, BarChart } from 'echarts/charts';
import { GridComponent, TooltipComponent, LegendComponent, MarkLineComponent, BrushComponent, ToolboxComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { count as fmtCount, ms as fmtMs, msTight, percent as fmtPct, timeLabel } from '../lib/format.js';

// Statically bundled: an addon's published assets live under /vendor/<addon>/,
// but Vite bakes the default base into ECharts' internal dynamic-import URLs, so
// code-splitting it would 404 those nested chunks. One bundle avoids that.
echarts.use([LineChart, BarChart, GridComponent, TooltipComponent, LegendComponent, MarkLineComponent, BrushComponent, ToolboxComponent, CanvasRenderer]);

const props = defineProps({
    // x axis timestamps in ms
    time: { type: Array, default: () => [] },
    // [{ name, data: number[], color, type?: 'line'|'bar', stack?, area?: bool }]
    series: { type: Array, default: () => [] },
    // vertical deploy lines: [{ time: ms, label? }]
    markers: { type: Array, default: () => [] },
    height: { type: Number, default: 200 },
    // 'ms' | 'count' | 'percent'
    unit: { type: String, default: 'count' },
    smooth: { type: Boolean, default: true },
    legend: { type: Boolean, default: true },
    // Enable drag-to-select a time range; emits `zoom` with [fromMs, toMs].
    brush: { type: Boolean, default: false },
});

const emit = defineEmits(['zoom']);

// How much time the axis covers — beyond a day and a half the labels need the date.
const spanMs = () => (props.time.length > 1 ? props.time[props.time.length - 1] - props.time[0] : 0);

const el = ref(null);
const flags = ref([]);
let chart = null;
let observer = null;
let probe = null;

// ECharts' canvas can't read CSS custom properties or oklch(): resolve a
// `var(--x)` / oklch / hex string to a concrete rgba() by painting it on a 1×1
// canvas and reading the pixel back.
function toRgb(color) {
    if (!color) return '#888';
    let c = color;
    if (c.startsWith('var(')) c = getComputedStyle(document.documentElement).getPropertyValue(c.slice(4, -1).trim()).trim() || '#888';
    if (!probe) probe = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
    probe.clearRect(0, 0, 1, 1);
    probe.fillStyle = '#000';
    probe.fillStyle = c;
    probe.fillRect(0, 0, 1, 1);
    const [r, g, b, a] = probe.getImageData(0, 0, 1, 1).data;
    return `rgba(${r},${g},${b},${(a / 255).toFixed(3)})`;
}
const rgba = (color, alpha) => toRgb(color).replace(/[\d.]+\)$/, alpha + ')');

const isDark = () => document.documentElement.classList.contains('dark') || !!el.value?.closest('.dark');

const fmt = (v) => (v == null ? '—' : props.unit === 'ms' ? fmtMs(v) : props.unit === 'percent' ? fmtPct(v) : fmtCount(v));
const axisVal = (v) => (props.unit === 'ms' ? msTight(v) : props.unit === 'percent' ? v * 100 + '%' : fmtCount(v));

function buildOption() {
    const dark = isDark();
    const muted = dark ? 'rgba(255,255,255,0.45)' : 'rgba(0,0,0,0.45)';
    const border = dark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.07)';
    const multi = props.series.length > 1;

    const markLine = props.markers.length
        ? {
              silent: true,
              symbol: 'none',
              lineStyle: { color: toRgb('#8b5cf6'), type: 'dashed', width: 1 },
              label: { show: false },
              data: props.markers.map((m) => ({ xAxis: m.time })),
          }
        : undefined;

    const series = props.series.map((s, i) => {
        const color = toRgb(s.color);
        const data = props.time.map((t, j) => [t, s.data?.[j] ?? null]);
        const populated = data.filter((d) => d[1] != null).length;
        const bar = (s.type || 'line') === 'bar';
        const stacked = !!s.stack;
        const base = {
            name: s.name,
            type: bar ? 'bar' : 'line',
            data,
            z: 2 + i,
            itemStyle: { color, borderRadius: bar ? [2, 2, 0, 0] : undefined },
        };
        if (bar) {
            Object.assign(base, { stack: s.stack, barMaxWidth: 14 });
        } else {
            Object.assign(base, {
                stack: s.stack,
                smooth: props.smooth ? 0.15 : false,
                showSymbol: populated <= Math.max(3, data.length * 0.15),
                symbolSize: 5,
                lineStyle: { width: 1.6, color },
                areaStyle: s.area ? { color, opacity: stacked ? 0.3 : 0.08 } : undefined,
            });
        }
        if (i === 0 && markLine) base.markLine = markLine;
        return base;
    });

    return {
        animation: false,
        toolbox: props.brush ? { show: false } : undefined,
        brush: props.brush
            ? {
                  xAxisIndex: 0,
                  brushType: 'lineX',
                  brushMode: 'single',
                  throttleType: 'debounce',
                  throttleDelay: 120,
                  transformable: false,
                  brushStyle: { color: rgba('var(--color-blue-500)', 0.12), borderColor: rgba('var(--color-blue-500)', 0.5), borderWidth: 1 },
              }
            : undefined,
        grid: { left: 8, right: 12, top: 10, bottom: multi ? 28 : 8, containLabel: true },
        legend: multi
            ? { bottom: 0, icon: 'roundRect', itemWidth: 10, itemHeight: 4, itemGap: 14, textStyle: { color: muted, fontSize: 11 } }
            : undefined,
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'line', lineStyle: { color: muted, width: 1, type: 'dashed' } },
            backgroundColor: dark ? '#1c1c22' : '#ffffff',
            borderColor: border,
            borderWidth: 1,
            padding: [6, 10],
            textStyle: { color: dark ? '#e5e7eb' : '#18181b', fontSize: 12 },
            formatter: (params) => {
                const head = `<div style="font-size:11px;opacity:.6;margin-bottom:3px">${timeLabel(params[0].value[0], spanMs())}</div>`;
                const rows = params
                    .map((p) => `<div style="display:flex;justify-content:space-between;gap:14px"><span>${p.marker}${p.seriesName}</span><b>${fmt(p.value[1])}</b></div>`)
                    .join('');
                return head + rows;
            },
        },
        xAxis: {
            type: 'time',
            axisLine: { show: false },
            axisTick: { show: false },
            axisLabel: { color: muted, fontSize: 10.5, hideOverlap: true, formatter: (v) => timeLabel(v, spanMs()) },
            splitLine: { show: false },
        },
        yAxis: {
            type: 'value',
            axisLabel: { color: muted, fontSize: 10.5, formatter: (v) => axisVal(v) },
            splitLine: { lineStyle: { color: border } },
        },
        series,
    };
}

// Place the deploy flags over the canvas, converting each marker's timestamp to
// a pixel x once the chart has drawn.
function placeFlags() {
    if (!chart || !props.markers.length) {
        flags.value = [];
        return;
    }
    const w = el.value?.clientWidth ?? 0;
    flags.value = props.markers
        .map((m) => {
            const x = chart.convertToPixel({ xAxisIndex: 0 }, m.time);
            return x != null && x >= 0 && x <= w ? { x, label: m.label || 'Deployment' } : null;
        })
        .filter(Boolean);
}

function armBrush() {
    if (chart && props.brush) {
        chart.dispatchAction({ type: 'takeGlobalCursor', key: 'brush', brushOption: { brushType: 'lineX', brushMode: 'single' } });
    }
}

function onBrushEnd(params) {
    const area = params.areas && params.areas[0];
    chart.dispatchAction({ type: 'brush', areas: [] });
    armBrush();
    if (!area || !area.coordRange) return;
    const [a, b] = area.coordRange;
    if (Math.abs(b - a) < 30000) return; // ignore < 30s drags / stray clicks
    emit('zoom', [Math.min(a, b), Math.max(a, b)]);
}

function render() {
    if (!chart) return;
    chart.setOption(buildOption(), true);
    placeFlags();
    armBrush();
}

onMounted(async () => {
    await nextTick();
    if (!el.value) return;
    chart = echarts.init(el.value, null, { renderer: 'canvas' });
    chart.on('finished', placeFlags);
    if (props.brush) chart.on('brushEnd', onBrushEnd);
    render();
    observer = new ResizeObserver(() => {
        if (chart) {
            chart.resize();
            placeFlags();
        }
    });
    observer.observe(el.value);
});

onBeforeUnmount(() => {
    observer && observer.disconnect();
    chart && chart.dispose();
    chart = null;
});

watch(() => [props.series, props.time, props.markers], render, { deep: true });
</script>
