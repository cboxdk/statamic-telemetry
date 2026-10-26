<template>
    <div
        role="button"
        tabindex="0"
        class="grid h-13 w-full cursor-pointer grid-cols-[4.5rem_minmax(0,1fr)_auto] items-center gap-3 border-b border-gray-100 px-4 text-left text-sm transition-colors hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800"
        :class="{ 'bg-blue-500/10': active }"
        @click="$emit('open', row.id)"
        @keydown.enter.prevent="$emit('open', row.id)"
    >
        <!-- One request as a person recognises it: "GET /about · 200", then title · entry:pages · cache hit.
             The row opens the trace; the facts on it filter (status, collection, cache) or lead on (edit the entry). -->
        <span class="tabular-nums text-xs text-gray-500">{{ clock(row.timestamp) }}</span>
        <span class="min-w-0">
            <span class="flex items-center gap-2">
                <span class="truncate font-mono text-[13px]">{{ label }}</span>
                <button v-if="row.status" type="button" class="shrink-0 rounded px-1 font-mono text-[10.5px] font-semibold hover:ring-1 hover:ring-gray-400" :class="[statusClass(row.status), isActive('status', row.status) ? 'ring-1 ring-blue-500' : '']" title="Click to filter by status" @click.stop="$emit('facet', 'status', row.status)">{{ row.status }}</button>
            </span>
            <span class="flex min-w-0 items-center gap-2 text-[11px] text-gray-500">
                <a v-if="row.title && row.editUrl" :href="row.editUrl" class="truncate no-underline hover:underline" title="Edit this entry" @click.stop>{{ row.title }}</a>
                <span v-else-if="row.title" class="truncate">{{ row.title }}</span>
                <button v-if="content" type="button" class="shrink-0 font-mono hover:underline" :class="{ 'text-blue-600 dark:text-blue-400': isActive('collection', row.collection) }" title="Click to filter by collection" @click.stop="$emit('facet', 'collection', row.collection)">{{ content }}</button>
                <span v-else-if="row.route && row.route !== row.path" class="truncate font-mono">{{ row.route }}</span>
                <button v-if="row.cache" type="button" class="shrink-0 hover:underline" :class="row.cache === 'hit' ? 'text-green-600 dark:text-green-400' : 'text-amber-600 dark:text-amber-400'" title="Click to filter by cache outcome" @click.stop="$emit('facet', 'cache', row.cache)">cache {{ row.cache }}</button>
                <span v-if="row.site && row.site !== 'default'" class="shrink-0">{{ row.site }}</span>
            </span>
        </span>
        <span class="flex items-center gap-3">
            <a v-if="row.editUrl" :href="row.editUrl" class="text-xs text-gray-400 no-underline hover:text-gray-700 hover:underline dark:hover:text-gray-200" title="Edit this entry in the CP" @click.stop>Edit</a>
            <span class="tabular-nums" :class="toneClass(row.durationMs)">{{ ms(row.durationMs) }}</span>
        </span>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { ms, clock } from '../lib/format.js';

const props = defineProps({
    row: { type: Object, required: true },
    active: { type: Boolean, default: false },
    // The list's current filters, so a chip can show it is the one applied.
    filters: { type: Object, default: () => ({}) },
});
defineEmits(['open', 'facet']);

const label = computed(() => (props.row.path ? `${props.row.method ? props.row.method + ' ' : ''}${props.row.path}` : props.row.name));
const content = computed(() => {
    const r = props.row;
    return r.collection ? `entry:${r.collection}` : r.taxonomy ? `${r.type || 'term'}:${r.taxonomy}` : r.type === 'glide' ? 'glide' : '';
});
const isActive = (dim, value) => String(props.filters?.[dim] ?? '') === String(value);
const statusClass = (s) => (s >= 500 ? 'bg-red-500/15 text-red-600 dark:text-red-400' : s >= 400 ? 'bg-amber-500/15 text-amber-700 dark:text-amber-400' : 'bg-gray-500/10 text-gray-500');
const toneClass = (v) => (v >= 1000 ? 'text-red-500' : v >= 300 ? 'text-orange-500' : 'text-green-600 dark:text-green-400');
</script>
