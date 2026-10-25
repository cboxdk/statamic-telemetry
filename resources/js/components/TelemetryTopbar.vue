<template>
    <div class="flex items-center gap-2">
        <!-- Look-back window -->
        <ToggleGroup :model-value="to ? null : window" size="sm" @update:model-value="pick">
            <ToggleItem v-for="w in presets" :key="w" :value="w" :label="w" />
        </ToggleGroup>

        <!-- Custom range -->
        <Popover align="end" v-model:open="rangeOpen">
            <template #trigger>
                <Button size="sm" :variant="to ? 'primary' : 'default'" :text="to ? rangeLabel : 'Custom'" />
            </template>
            <div class="w-80 space-y-3 p-1">
                <label class="block space-y-1">
                    <span class="text-xs font-medium text-gray-500">From</span>
                    <Input type="datetime-local" v-model="fromInput" />
                </label>
                <label class="block space-y-1">
                    <span class="text-xs font-medium text-gray-500">To</span>
                    <Input type="datetime-local" v-model="toInput" />
                </label>
                <div class="flex items-center justify-between gap-2 pt-1">
                    <Button size="sm" variant="ghost" text="Clear" :disabled="!to" @click="clearRange" />
                    <Button size="sm" variant="primary" text="Apply" :disabled="!validRange" @click="applyRange" />
                </div>
            </div>
        </Popover>

        <!-- Auto-refresh (Select is block/w-full by default — box it) -->
        <div class="w-24 shrink-0" v-tooltip="to ? 'A custom range is fixed in time' : null">
            <Select
                size="sm"
                :options="refreshOptions"
                :model-value="to ? 'off' : refresh"
                :disabled="!!to"
                @update:model-value="setRefresh"
            />
        </div>
        <Button size="sm" text="Refresh" :loading="refreshing" @click="refreshNow" />
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { Button, Input, Popover, Select, ToggleGroup, ToggleItem } from '@statamic/cms/ui';
import { router } from '@statamic/cms/inertia';
import { useTelemetryUrl, windowSeconds } from '../lib/useTelemetryUrl.js';
import { clock } from '../lib/format.js';

const props = defineProps({
    window: { type: String, default: '1h' },
    windows: { type: Object, default: () => ({}) },
    from: { type: Number, default: null },
    to: { type: Number, default: null },
    // The page's own params to carry through navigations (signal, filters, …).
    carry: { type: Function, default: () => ({}) },
});

const PRESETS = ['15m', '1h', '6h', '24h', '7d'];
const presets = computed(() => PRESETS.filter((w) => props.windows[w]));

const { go } = useTelemetryUrl(() => ({ window: props.window, from: props.from, to: props.to, ...props.carry() }));

function pick(w) {
    if (w && w !== props.window) go({ window: w, from: null, to: null });
    else if (w === props.window && props.to) go({ from: null, to: null });
}

// ── Custom range ─────────────────────────────────────────────────────────────
const rangeOpen = ref(false);
const toLocal = (sec) => {
    const d = new Date(sec * 1000);
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
};
const fromInput = ref(props.from ? toLocal(props.from) : '');
const toInput = ref(props.to ? toLocal(props.to) : '');
watch(() => [props.from, props.to], ([f, t]) => {
    fromInput.value = f ? toLocal(f) : '';
    toInput.value = t ? toLocal(t) : '';
});
// Opening the picker starts from the window you are looking at, not blank fields.
watch(rangeOpen, (open) => {
    if (!open || props.to) return;
    const now = Math.floor(Date.now() / 1000);
    fromInput.value = toLocal(now - windowSeconds(props.window));
    toInput.value = toLocal(now);
});
const toSec = (s) => (s ? Math.round(new Date(s).getTime() / 1000) : null);
const validRange = computed(() => {
    const f = toSec(fromInput.value);
    const t = toSec(toInput.value);
    return !!f && !!t && t - f >= 60 && t - f <= 7 * 86400;
});
const rangeLabel = computed(() => (props.to ? `${clock(props.from * 1000)}–${clock(props.to * 1000)}` : ''));

function applyRange() {
    rangeOpen.value = false;
    go({ from: toSec(fromInput.value), to: toSec(toInput.value) });
}
function clearRange() {
    rangeOpen.value = false;
    go({ from: null, to: null });
}

// ── Auto-refresh ─────────────────────────────────────────────────────────────
const KEY = 'statamic-telemetry.refresh';
const refreshOptions = [
    { label: 'Off', value: 'off' },
    { label: '10s', value: '10' },
    { label: '30s', value: '30' },
    { label: '60s', value: '60' },
];
const refresh = ref('off');
const refreshing = ref(false);
let timer = null;

function refreshNow() {
    refreshing.value = true;
    router.reload({ preserveScroll: true, preserveState: true, onFinish: () => (refreshing.value = false) });
}
function arm() {
    if (timer) clearInterval(timer);
    timer = null;
    if (refresh.value !== 'off' && !props.to) timer = setInterval(refreshNow, Number(refresh.value) * 1000);
}
function setRefresh(v) {
    refresh.value = v || 'off';
    try { localStorage.setItem(KEY, refresh.value); } catch {}
    arm();
}
onMounted(() => {
    try { refresh.value = localStorage.getItem(KEY) || 'off'; } catch {}
    arm();
});
watch(() => props.to, arm);
onBeforeUnmount(() => timer && clearInterval(timer));
</script>
