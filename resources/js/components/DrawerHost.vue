<template>
    <!-- One Statamic Stack per drawer entry in the URL; Stacks nest on their own. -->
    <Stack
        v-for="entry in drawers"
        :key="keyOf(entry)"
        :open="true"
        size="half"
        inset
        :title="titleOf(entry)"
        icon="chart-monitoring-indicator"
        @update:open="(open) => !open && popDrawer()"
    >
        <TraceDrawer v-if="entry.kind === 'trace'" :id="entry.id" />
        <div v-else class="p-6 text-sm text-gray-500">Unknown drawer type.</div>
    </Stack>
</template>

<script setup>
import { Stack } from '@statamic/cms/ui';
import TraceDrawer from './TraceDrawer.vue';
import { useTelemetryUrl } from '../lib/useTelemetryUrl.js';

const { drawers, popDrawer } = useTelemetryUrl();

const keyOf = (e) => (e.kind === 'entity' ? `entity:${e.type}:${e.value}` : `${e.kind}:${e.id}`);
const titleOf = (e) => (e.kind === 'trace' ? `Trace ${String(e.id).slice(0, 12)}` : e.kind);
</script>
