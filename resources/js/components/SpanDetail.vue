<template>
    <div class="max-h-[45vh] overflow-y-auto border-t border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-gray-950/60">
        <div class="flex items-center gap-2 px-4 py-2.5">
            <strong class="truncate font-mono text-xs">{{ span.name }}</strong>
            <span class="font-mono text-[11px] text-gray-500">
                {{ [span.kind, span.service, ms(span.durationMs)].filter(Boolean).join(' · ') }}
                <template v-if="span.offsetMs >= 1"> · +{{ ms(span.offsetMs) }}</template>
            </span>
            <Button class="ms-auto" size="xs" variant="ghost" text="Close" @click="$emit('close')" />
        </div>

        <dl v-if="entries.length" class="px-4 pb-3">
            <div
                v-for="[key, value] in entries"
                :key="key"
                class="grid grid-cols-[minmax(110px,30%)_1fr] gap-3 border-b border-gray-200/70 py-1.5 last:border-0 dark:border-gray-800/70"
            >
                <dt class="truncate font-mono text-[11px] text-gray-500" :title="key">{{ key }}</dt>
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
        <p v-else class="px-4 pb-3 text-xs text-gray-500">No attributes recorded on this span.</p>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { Button, Dropdown, DropdownMenu, DropdownItem } from '@statamic/cms/ui';
import { ms } from '../lib/format.js';

const props = defineProps({ span: { type: Object, required: true } });
defineEmits(['close']);

const entries = computed(() => Object.entries(props.span.attributes || {}));
const copy = (text) => navigator.clipboard?.writeText(String(text));

// Span attribute → Explore facet it can filter on.
const FACETS = { 'http.route': 'route', 'http.request.method': 'method', 'http.response.status_code': 'status' };
const facetFor = (key) => FACETS[key] || null;
const exploreHref = (key, value) => {
    const base = location.pathname.replace(/\/telemetry(\/.*)?$/, '/telemetry/explore');
    const params = new URLSearchParams(location.search);
    ['drawer', 'id', 'groupBy'].forEach((k) => params.delete(k));
    params.set('signal', 'requests');
    params.set(facetFor(key), String(value));
    return `${base}?${params.toString()}`;
};
</script>
