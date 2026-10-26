<template>
    <TelemetryPage :title="__('Telemetry doctor')" icon="pulse" :nav="nav" :timebar="false">
        <p class="-mt-2 text-sm text-gray-500">{{ __(`The telemetry pipeline at a glance — what is on, where it ships, how deep the spool is, and whether the audit log's backend answers.`) }}</p>

        <div class="grid gap-6 md:grid-cols-2">
            <Card class="space-y-2">
                <div class="text-[10.5px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Spool') }}</div>
                <div class="flex items-center gap-2 text-xl font-bold leading-tight"><Dot :tone="spool.tone" />{{ spool.value }}</div>
                <div class="text-sm text-gray-500">{{ spool.detail }}</div>
            </Card>
            <Card class="space-y-2">
                <div class="text-[10.5px] font-semibold uppercase tracking-wider text-gray-500">{{ __('Logs backend') }}</div>
                <div class="flex items-center gap-2 text-xl font-bold leading-tight"><Dot :tone="backend.tone" />{{ backend.value }}</div>
                <div class="text-sm text-gray-500">
                    {{ result.backendDetail }}
                    <template v-if="backendHost"><br>{{ backendHost }}{{ result.hasToken ? ' · ' + __('token set') : '' }}{{ result.hasTenant ? ' · ' + __('tenant set') : '' }}</template>
                </div>
            </Card>
        </div>

        <Section :heading="__('Pipeline')">
            <div v-for="row in rows" :key="row.label" class="grid grid-cols-[12rem_1fr] items-baseline gap-4 border-b border-gray-200 py-3 last:border-0 dark:border-gray-800">
                <div class="flex items-center gap-2 font-medium"><Dot :tone="row.tone" />{{ row.label }}</div>
                <div>
                    <div class="tabular-nums">{{ row.value }}</div>
                    <div class="mt-0.5 text-xs text-gray-500">{{ row.note }}</div>
                </div>
            </div>
        </Section>

        <p class="text-xs text-gray-500">{{ __('For the full end-to-end check — store round trip, series budget, redaction, Prometheus and OTLP reachability — run ') }}<code class="rounded bg-gray-100 px-1.5 py-0.5 font-mono dark:bg-gray-800">php artisan telemetry:doctor</code>.</p>
    </TelemetryPage>
</template>

<script setup>
import { computed, h } from 'vue';
import { Card } from '@statamic/cms/ui';
import TelemetryPage from '../components/TelemetryPage.vue';
import Section from '../components/Section.vue';

const props = defineProps({ nav: { type: Array, default: () => [] }, result: Object });

const color = (tone) => ({ ok: 'bg-green-500', warn: 'bg-orange-500', danger: 'bg-red-500' }[tone] || 'bg-gray-400');
const Dot = (p) => h('span', { class: ['inline-block h-2.5 w-2.5 flex-none rounded-full', color(p.tone)] });

const yesNo = (on) => __(on ? 'enabled' : 'disabled');

const spool = computed(() => {
    const r = props.result;
    if (r.spoolError) return { tone: 'danger', value: __('unavailable'), detail: __('Could not read the spool: :error', { error: r.spoolError }) };
    if (!r.spoolEnabled) return { tone: 'dim', value: __('direct (no spool)'), detail: __('Spans and events POST at request terminate; no queue to drain.') };
    if ((r.spoolSize ?? 0) > 0) return { tone: 'warn', value: __(':count pending', { count: r.spoolSize }), detail: __('OTLP spool (:driver) — entries buffered until telemetry:flush ships them.', { driver: r.spoolDriver }) };
    return { tone: 'ok', value: __('drained (0 pending)'), detail: __('OTLP spool (:driver) — drained.', { driver: r.spoolDriver }) };
});

const backend = computed(() => {
    const r = props.result.backendReachable;
    if (r === true) return { tone: 'ok', value: __('reachable') };
    if (r === false) return { tone: 'danger', value: __('unreachable') };
    return { tone: 'dim', value: __('not configured') };
});

const backendHost = computed(() => {
    if (!props.result.backendUrl) return null;
    try { return new URL(props.result.backendUrl).host; } catch { return props.result.backendUrl; }
});

const rows = computed(() => {
    const r = props.result;
    return [
        { label: __('Telemetry'), value: yesNo(r.telemetryEnabled), tone: r.telemetryEnabled ? 'ok' : 'danger', note: __('The base laravel-telemetry master switch.') },
        { label: __('Statamic overlay'), value: yesNo(r.overlayEnabled), tone: r.overlayEnabled ? 'ok' : 'danger', note: __('This addon (statamic-telemetry.enabled).') },
        { label: __('Service'), value: r.serviceName, tone: 'dim', note: __('service.name stamped on every signal.') },
        { label: __('Metric store'), value: r.storeDriver, tone: 'dim', note: __('Where counters/gauges live for the Prometheus scrape.') },
        { label: __('OTLP endpoint'), value: r.otlpEndpoint || '—', tone: 'dim', note: __('Where traces and events ship.') },
        { label: __('Exporters'), value: r.exporters?.length ? r.exporters.join(', ') : __('none'), tone: 'dim', note: __('Extra exporters beyond the default OTLP transport.') },
    ];
});
</script>
