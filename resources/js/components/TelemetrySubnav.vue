<template>
    <nav class="-mb-px flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="Telemetry">
        <Link
            v-for="item in items"
            :key="item.key"
            :href="href(item.path, item.params)"
            class="whitespace-nowrap border-b-2 px-3.5 py-2 text-sm no-underline transition-colors"
            :class="item.active
                ? 'border-blue-500 font-medium text-gray-900 dark:text-white'
                : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'"
            :aria-current="item.active ? 'page' : null"
        >{{ item.label }}</Link>
    </nav>
</template>

<script setup>
import { Link } from '@statamic/cms/inertia';
import { useTelemetryUrl } from '../lib/useTelemetryUrl.js';

const props = defineProps({
    // [{ key, label, path, params, active }] — from TelemetryNav on the server.
    items: { type: Array, default: () => [] },
    // Time state (and nothing page-specific) to carry into the other pages.
    carry: { type: Function, default: () => ({}) },
});

const { href } = useTelemetryUrl(() => props.carry());
</script>
