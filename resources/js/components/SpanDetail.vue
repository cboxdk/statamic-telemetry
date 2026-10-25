<template>
    <!-- One span's attributes — the body of the nested span Stack. -->
    <div class="flex h-full min-h-0 flex-col">
        <div class="border-b border-gray-200 px-5 py-3 dark:border-gray-800">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 font-mono text-xs text-gray-500">
                <span v-if="span.kind">{{ span.kind }}</span>
                <span v-if="span.service">{{ span.service }}</span>
                <span class="tabular-nums" :class="toneClass(span.durationMs)">{{ ms(span.durationMs) }}</span>
                <span v-if="span.offsetMs >= 1" class="tabular-nums">starts +{{ ms(span.offsetMs) }}</span>
                <Badge v-if="span.error" text="error" color="red" size="sm" />
            </div>
        </div>

        <dl v-if="entries.length" class="min-h-0 flex-1 overflow-y-auto px-5 py-2">
            <div
                v-for="[key, value] in entries"
                :key="key"
                class="grid grid-cols-[minmax(140px,40%)_1fr] gap-3 border-b border-gray-200 py-2 last:border-0 dark:border-gray-800"
            >
                <dt class="truncate pt-px font-mono text-[11px] text-gray-500" :title="key">{{ key }}</dt>
                <dd class="flex min-w-0 items-start gap-1">
                    <span class="min-w-0 flex-1 break-all font-mono text-xs" :class="{ 'text-red-500': key === 'status' }">{{ value }}</span>
                    <Dropdown align="end">
                        <template #trigger>
                            <Button size="2xs" variant="ghost" icon-only text="⋯" :aria-label="`Actions for ${key}`" />
                        </template>
                        <DropdownMenu>
                            <DropdownItem text="Copy value" @click="copy(value)" />
                            <DropdownItem v-if="facetFor(key)" :text="`Filter Explore by ${facetFor(key)}`" :href="exploreHref(key, value)" />
                        </DropdownMenu>
                    </Dropdown>
                </dd>
            </div>
        </dl>
        <p v-else class="px-5 py-6 text-sm text-gray-500">No attributes recorded on this span.</p>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { Badge, Button, Dropdown, DropdownMenu, DropdownItem } from '@statamic/cms/ui';
import { ms } from '../lib/format.js';

const props = defineProps({ span: { type: Object, required: true } });

const entries = computed(() => Object.entries(props.span.attributes || {}));
const copy = (text) => navigator.clipboard?.writeText(String(text));
const toneClass = (v) => (v >= 1000 ? 'text-red-500' : v >= 300 ? 'text-orange-500' : 'text-green-600 dark:text-green-400');

// Span attribute → Explore facet it can filter on.
const FACETS = { 'http.route': 'route', 'http.request.method': 'method', 'http.response.status_code': 'status' };
const facetFor = (key) => FACETS[key] || null;
const exploreHref = (key, value) => {
    const base = location.pathname.replace(/\/telemetry(\/.*)?$/, '/telemetry/explore');
    const params = new URLSearchParams(location.search);
    ['drawer', 'groupBy'].forEach((k) => params.delete(k));
    params.set('signal', 'requests');
    params.set(facetFor(key), String(value));
    return `${base}?${params.toString()}`;
};
</script>
