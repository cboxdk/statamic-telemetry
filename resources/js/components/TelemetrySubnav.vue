<template>
    <nav class="-mb-px flex flex-wrap items-stretch gap-x-1 border-b border-gray-200 dark:border-gray-800" aria-label="Telemetry">
        <template v-for="(item, i) in items" :key="item.key">
            <!-- A divider where the group changes: Overview/Explore | activity | Statamic | tools -->
            <span v-if="i > 0 && items[i - 1].group !== item.group" class="mx-2 my-2 w-px bg-gray-200 dark:bg-gray-800" aria-hidden="true" />
            <Link
                :href="href(item.path, item.params)"
                class="whitespace-nowrap border-b-2 px-2.5 py-2 text-[13px] no-underline transition-colors"
                :class="item.active
                    ? 'border-blue-500 font-medium text-gray-900 dark:text-white'
                    : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'"
                :aria-current="item.active ? 'page' : null"
            >{{ item.label }}</Link>
        </template>
    </nav>
</template>

<script setup>
import { Link } from '@statamic/cms/inertia';
import { useTelemetryUrl } from '../lib/useTelemetryUrl.js';

const props = defineProps({
    // [{ key, label, path, params, group, active }] — from TelemetryNav on the server.
    items: { type: Array, default: () => [] },
    // Time state (and nothing page-specific) to carry into the other pages.
    carry: { type: Function, default: () => ({}) },
});

const { href } = useTelemetryUrl(() => props.carry());
</script>
