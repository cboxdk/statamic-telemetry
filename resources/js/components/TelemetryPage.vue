<template>
    <div class="max-w-page mx-auto">
        <Head :title="title" />

        <Header :title="title" :icon="icon">
            <template #actions>
                <slot name="actions" />
                <TelemetryTopbar v-if="timebar" :window="window" :windows="windows" :from="from" :to="to" :carry="carry" />
            </template>
        </Header>

        <TelemetrySubnav :items="nav" :carry="timeOnly" class="mb-6" />

        <div class="space-y-6">
            <slot />
        </div>

        <DrawerHost />
    </div>
</template>

<script setup>
import { computed, inject, watchEffect, onBeforeUnmount } from 'vue';
import { Header } from '@statamic/cms/ui';
import { Head } from '@statamic/cms/inertia';
import TelemetryTopbar from './TelemetryTopbar.vue';
import TelemetrySubnav from './TelemetrySubnav.vue';
import DrawerHost from './DrawerHost.vue';

const props = defineProps({
    title: { type: String, required: true },
    icon: { type: String, default: 'chart-monitoring-indicator' },
    nav: { type: Array, default: () => [] },
    window: { type: String, default: '1h' },
    windows: { type: Object, default: () => ({}) },
    from: { type: Number, default: null },
    to: { type: Number, default: null },
    // Page-specific params the topbar should keep when changing the window.
    carry: { type: Function, default: () => ({}) },
    // Pages without a time dimension (the doctor) hide the period controls.
    timebar: { type: Boolean, default: true },
});

// The CP's header breadcrumb comes from the sidebar: "Telemetry / <active child>".
// Only Overview, Explore, Content and Doctor are sidebar children, so on any
// other page the child crumb would read "Overview" (its pattern covers every
// /telemetry/* URL). Append the page's own title there, the way core pages do.
const SIDEBAR_CHILDREN = ['overview', 'explore', 'content', 'doctor'];
const layout = inject('layout', null);
const activeKey = computed(() => props.nav.find((item) => item.active)?.key);
watchEffect(() => {
    if (!layout?.additionalBreadcrumbs) return;
    layout.additionalBreadcrumbs.value = activeKey.value && !SIDEBAR_CHILDREN.includes(activeKey.value)
        ? [{ display: props.title, url: null, links: [] }]
        : [];
});
onBeforeUnmount(() => {
    if (layout?.additionalBreadcrumbs) layout.additionalBreadcrumbs.value = [];
});

// Other pages get the time state only — never this page's filters.
const timeOnly = () => ({ window: props.window, from: props.from, to: props.to });
</script>
