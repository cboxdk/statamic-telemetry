<template>
    <div>
        <div v-if="!meta.available" class="rounded-lg border border-gray-200 p-4 text-sm text-gray-500 dark:border-gray-700">
            Telemetry for this entry appears once it has been saved and requested.
        </div>

        <template v-else>
            <div class="grid grid-cols-2 gap-3">
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <div class="text-xs uppercase tracking-wide text-gray-500">Requests · {{ days }}d</div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums">{{ fmt(meta.requests.count) }}</div>
                </div>
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <div class="text-xs uppercase tracking-wide text-gray-500">p95 latency</div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums" :style="{ color: tone(meta.requests.p95) }">{{ ms(meta.requests.p95) }}</div>
                </div>
            </div>

            <div v-if="meta.changes.length" class="mt-4">
                <div class="mb-1 text-xs uppercase tracking-wide text-gray-500">Change history</div>
                <div v-for="(c, i) in meta.changes" :key="i" class="flex items-center gap-2 border-b border-gray-200 py-1.5 text-sm last:border-0 dark:border-gray-800">
                    <span class="w-32 shrink-0 text-xs text-gray-500">{{ date(c.timestamp) }}</span>
                    <Badge :text="c.action" :color="color(c.action)" size="sm" />
                    <span class="flex-1 truncate text-gray-500">{{ c.who || '—' }}<template v-if="c.changed"> · {{ c.changed }}</template></span>
                </div>
            </div>

            <p v-if="!meta.requests.count && !meta.changes.length" class="mt-3 text-sm text-gray-500">
                No requests or changes for this entry in the last {{ days }} days.
            </p>
        </template>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { Fieldtype } from '@statamic/cms';
import { Badge } from '@statamic/cms/ui';
import { dateTime } from '../lib/format.js';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
Fieldtype.use(emit, props);

const meta = computed(() => props.meta || { available: false });
const days = computed(() => Math.round((meta.value.window || 604800) / 86400));

const fmt = (n) => new Intl.NumberFormat().format(n || 0);
const ms = (v) => (!v || v <= 0 ? '—' : v >= 1000 ? (v / 1000).toFixed(2) + ' s' : Math.round(v) + ' ms');
const tone = (v) => (v >= 1000 ? 'var(--color-red-500)' : v >= 300 ? 'var(--color-orange-500)' : 'var(--color-green-500)');
const date = (ts) => dateTime(ts);
const color = (a) => ({ created: 'green', published: 'green', uploaded: 'green', updated: 'blue', deleted: 'red', unpublished: 'orange' }[a] || 'default');
</script>
