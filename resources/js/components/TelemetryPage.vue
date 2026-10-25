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

// Other pages get the time state only — never this page's filters.
const timeOnly = () => ({ window: props.window, from: props.from, to: props.to });
</script>
