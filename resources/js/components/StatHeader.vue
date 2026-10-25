<template>
    <div class="grid grid-cols-[repeat(auto-fit,minmax(8.5rem,1fr))] gap-2">
        <Card v-for="(stat, i) in items" :key="i" class="py-3! space-y-0!">
            <div class="text-[10.5px] font-semibold uppercase tracking-wider text-gray-500">{{ stat.label }}</div>
            <div class="mt-1 text-xl font-bold leading-tight tabular-nums" :style="stat.tone ? { color: stat.tone } : undefined">{{ stat.value }}</div>
        </Card>
    </div>
</template>

<script setup>
import { Card } from '@statamic/cms/ui';

defineProps({
    // [{ label, value, tone? }] — tone is a CSS color (e.g. var(--color-red-500)).
    items: { type: Array, default: () => [] },
});
</script>
