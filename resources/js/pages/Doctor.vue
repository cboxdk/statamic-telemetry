<template>
    <div>
        <Head title="Telemetry doctor" />
        <Header title="Telemetry doctor" icon="chart-monitoring-indicator" />
        <p class="mb-5 text-sm text-gray-500">The telemetry pipeline at a glance — what is on, where it ships, how deep the spool is, and whether the audit log's backend answers.</p>

        <div class="mb-4 grid gap-4 md:grid-cols-2">
            <Widget title="Spool">
                <div class="flex items-center gap-2 text-xl font-semibold">
                    <Dot :tone="spool.tone" />{{ spool.value }}
                </div>
                <div class="mt-2 text-sm text-gray-500">{{ spool.detail }}</div>
            </Widget>
            <Widget title="Logs backend">
                <div class="flex items-center gap-2 text-xl font-semibold">
                    <Dot :tone="backend.tone" />{{ backend.value }}
                </div>
                <div class="mt-2 text-sm text-gray-500">
                    {{ result.backendDetail }}
                    <template v-if="backendHost"><br>{{ backendHost }}{{ result.hasToken ? ' · token set' : '' }}{{ result.hasTenant ? ' · tenant set' : '' }}</template>
                </div>
            </Widget>
        </div>

        <Panel heading="Pipeline">
            <div v-for="row in rows" :key="row.label" class="grid items-baseline gap-4 border-b border-gray-200 px-5 py-3 last:border-0 dark:border-gray-800" style="grid-template-columns: 12rem 1fr">
                <div class="flex items-center gap-2 font-medium"><Dot :tone="row.tone" />{{ row.label }}</div>
                <div>
                    <div class="tabular-nums">{{ row.value }}</div>
                    <div class="mt-0.5 text-xs text-gray-500">{{ row.note }}</div>
                </div>
            </div>
        </Panel>

        <p class="mt-4 text-xs text-gray-500">For the full end-to-end check — store round trip, series budget, redaction, Prometheus and OTLP reachability — run <code class="rounded bg-gray-500/10 px-1.5 py-0.5 font-mono">php artisan telemetry:doctor</code>.</p>
    </div>
</template>

<script setup>
import { computed, h } from 'vue';
import { Header, Widget, Panel } from '@statamic/cms/ui';
import { Head } from '@statamic/cms/inertia';

const props = defineProps({ result: Object });

const color = (tone) => ({ ok: 'var(--color-green-500)', warn: 'var(--color-orange-500)', danger: 'var(--color-red-500)' }[tone] || 'var(--color-gray-500)');

const Dot = (p) => h('span', { class: 'inline-block h-2.5 w-2.5 flex-none rounded-full', style: { background: color(p.tone) } });

const yesNo = (on) => (on ? 'enabled' : 'disabled');

const spool = computed(() => {
    const r = props.result;
    if (r.spoolError) return { tone: 'danger', value: 'unavailable', detail: 'Could not read the spool: ' + r.spoolError };
    if (!r.spoolEnabled) return { tone: 'dim', value: 'direct (no spool)', detail: 'Spans and events POST at request terminate; no queue to drain.' };
    if ((r.spoolSize ?? 0) > 0) return { tone: 'warn', value: r.spoolSize + ' pending', detail: `OTLP spool (${r.spoolDriver}) — entries buffered until telemetry:flush ships them.` };
    return { tone: 'ok', value: 'drained (0 pending)', detail: `OTLP spool (${r.spoolDriver}) — drained.` };
});

const backend = computed(() => {
    const r = props.result.backendReachable;
    if (r === true) return { tone: 'ok', value: 'reachable' };
    if (r === false) return { tone: 'danger', value: 'unreachable' };
    return { tone: 'dim', value: 'not configured' };
});

const backendHost = computed(() => {
    if (!props.result.backendUrl) return null;
    try { return new URL(props.result.backendUrl).host; } catch { return props.result.backendUrl; }
});

const rows = computed(() => {
    const r = props.result;
    return [
        { label: 'Telemetry', value: yesNo(r.telemetryEnabled), tone: r.telemetryEnabled ? 'ok' : 'danger', note: 'The base laravel-telemetry master switch.' },
        { label: 'Statamic overlay', value: yesNo(r.overlayEnabled), tone: r.overlayEnabled ? 'ok' : 'danger', note: 'This addon (statamic-telemetry.enabled).' },
        { label: 'Service', value: r.serviceName, tone: 'dim', note: 'service.name stamped on every signal.' },
        { label: 'Metric store', value: r.storeDriver, tone: 'dim', note: 'Where counters/gauges live for the Prometheus scrape.' },
        { label: 'OTLP endpoint', value: r.otlpEndpoint || '—', tone: 'dim', note: 'Where traces and events ship.' },
        { label: 'Exporters', value: r.exporters.length ? r.exporters.join(', ') : 'none', tone: 'dim', note: 'Extra exporters beyond the default OTLP transport.' },
    ];
});
</script>
