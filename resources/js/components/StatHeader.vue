<template>
    <div class="tel-statbar">
        <div v-for="(stat, i) in items" :key="i" class="tel-stat">
            <div class="tel-label">{{ stat.label }}</div>
            <div class="tel-metric" :style="stat.tone ? { color: stat.tone } : undefined">{{ stat.value }}</div>
        </div>
    </div>
</template>

<script setup>
defineProps({
    // [{ label, value, tone? }]
    items: { type: Array, default: () => [] },
});
</script>
