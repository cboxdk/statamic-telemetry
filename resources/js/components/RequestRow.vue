<template>
    <!-- One request as a person recognises it: "GET /about · 200", then title · entry:pages · cache hit. -->
    <button
        type="button"
        class="grid h-13 w-full grid-cols-[4.5rem_minmax(0,1fr)_auto] items-center gap-3 border-b border-gray-100 px-4 text-left text-sm transition-colors hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800"
        :class="{ 'bg-blue-500/10': active }"
        @click="$emit('open', row.id)"
    >
        <span class="tabular-nums text-xs text-gray-500">{{ clock(row.timestamp) }}</span>
        <span class="min-w-0">
            <span class="flex items-center gap-2">
                <span class="truncate font-mono text-[13px]">{{ label }}</span>
                <span v-if="row.status" class="shrink-0 rounded px-1 font-mono text-[10.5px] font-semibold" :class="statusClass(row.status)">{{ row.status }}</span>
            </span>
            <span class="flex min-w-0 items-center gap-2 text-[11px] text-gray-500">
                <span v-if="row.title" class="truncate">{{ row.title }}</span>
                <span v-if="content" class="shrink-0 font-mono">{{ content }}</span>
                <span v-else-if="row.route && row.route !== row.path" class="truncate font-mono">{{ row.route }}</span>
                <span v-if="row.cache" class="shrink-0" :class="row.cache === 'hit' ? 'text-green-600 dark:text-green-400' : 'text-amber-600 dark:text-amber-400'">cache {{ row.cache }}</span>
                <span v-if="row.site && row.site !== 'default'" class="shrink-0">{{ row.site }}</span>
            </span>
        </span>
        <span class="tabular-nums" :class="toneClass(row.durationMs)">{{ ms(row.durationMs) }}</span>
    </button>
</template>

<script setup>
import { computed } from 'vue';
import { ms, clock } from '../lib/format.js';

const props = defineProps({
    row: { type: Object, required: true },
    active: { type: Boolean, default: false },
});
defineEmits(['open']);

const label = computed(() => (props.row.path ? `${props.row.method ? props.row.method + ' ' : ''}${props.row.path}` : props.row.name));
const content = computed(() => {
    const r = props.row;
    return r.collection ? `entry:${r.collection}` : r.taxonomy ? `${r.type || 'term'}:${r.taxonomy}` : r.type === 'glide' ? 'glide' : '';
});
const statusClass = (s) => (s >= 500 ? 'bg-red-500/15 text-red-600 dark:text-red-400' : s >= 400 ? 'bg-amber-500/15 text-amber-700 dark:text-amber-400' : 'bg-gray-500/10 text-gray-500');
const toneClass = (v) => (v >= 1000 ? 'text-red-500' : v >= 300 ? 'text-orange-500' : 'text-green-600 dark:text-green-400');
</script>
