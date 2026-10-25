<template>
    <!-- A stat tile: eyebrow · value · delta / sub. telemetry-ui's canonical
         card is a row of these above a time chart. Linked when `href` is set. -->
    <component
        :is="href ? Link : 'div'"
        v-bind="href ? { href } : {}"
        :class="href ? 'block rounded-lg no-underline hover:bg-gray-50 dark:hover:bg-gray-800/40' : null"
    >
        <div class="text-[10.5px] font-semibold uppercase tracking-wider text-gray-500">{{ label }}</div>
        <div class="mt-0.5 text-xl font-bold leading-tight tabular-nums" :class="dim ? 'text-gray-500' : tone">{{ value ?? '—' }}</div>
        <div v-if="delta" class="mt-0.5 font-mono text-[11px]" :class="tone || 'text-gray-500'">{{ delta }}</div>
        <div v-if="sub" class="mt-0.5 text-xs text-gray-500">{{ sub }}</div>
    </component>
</template>

<script setup>
import { Link } from '@statamic/cms/inertia';

defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], default: null },
    delta: { type: String, default: '' },
    sub: { type: String, default: '' },
    tone: { type: String, default: '' },
    dim: { type: Boolean, default: false },
    href: { type: String, default: null },
});
</script>
