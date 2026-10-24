<template>
    <nav class="tel-tabs">
        <Link
            v-for="tab in tabs"
            :key="tab.key"
            :href="tab.href"
            class="tel-tab"
            :class="{ 'tel-tab--active': tab.key === active }"
        >{{ tab.label }}</Link>
    </nav>
</template>

<script setup>
import { computed } from 'vue';
import { Link } from '@statamic/cms/inertia';

const props = defineProps({
    // 'overview' | 'explore' | 'statamic'
    active: { type: String, required: true },
    window: { type: String, default: '1h' },
    dashboardUrl: { type: String, required: true },
    exploreUrl: { type: String, required: true },
});

const tabs = computed(() => [
    { key: 'overview', label: 'Overview', href: `${props.dashboardUrl}?section=overview&window=${props.window}` },
    { key: 'explore', label: 'Explore', href: `${props.exploreUrl}?window=${props.window}` },
    { key: 'activity', label: 'Activity', href: `${props.dashboardUrl}?section=activity&window=${props.window}` },
    { key: 'statamic', label: 'Statamic', href: `${props.dashboardUrl}?section=statamic&window=${props.window}` },
]);
</script>
