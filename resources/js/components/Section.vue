<template>
    <Card :inset="inset" :class="inset ? '' : 'space-y-4'">
        <!-- The one card every telemetry section uses: a Card with its heading inside it —
             no separate header strip, same radius, same padding, whether it holds tiles,
             a chart, a table (`inset`, so rows can run edge to edge) or a list. -->
        <div v-if="heading || $slots.actions" class="flex items-start justify-between gap-3" :class="inset ? 'px-4 pt-4 pb-3 sm:px-4.5' : ''">
            <div class="min-w-0">
                <h3 class="text-sm font-semibold leading-tight">{{ heading }}</h3>
                <p v-if="subheading" class="mt-0.5 text-xs text-gray-500">{{ subheading }}</p>
            </div>
            <div v-if="$slots.actions" class="shrink-0 text-xs text-gray-500"><slot name="actions" /></div>
        </div>
        <slot />
    </Card>
</template>

<script setup>
import { Card } from '@statamic/cms/ui';

defineProps({
    heading: { type: String, default: '' },
    subheading: { type: String, default: '' },
    // No padding: the content (a table, a row list) manages its own edges.
    inset: { type: Boolean, default: false },
});
</script>
