<template>
    <div>
        <Head title="Telemetry" />

        <Header title="Telemetry" icon="chart-monitoring-indicator">
            <select
                :value="window"
                @change="changeWindow($event.target.value)"
                class="h-9 rounded-lg border border-gray-300 bg-white px-2 text-sm dark:border-gray-700 dark:bg-gray-800"
            >
                <option v-for="(label, value) in windows" :key="value" :value="value">{{ label }}</option>
            </select>
        </Header>

        <nav class="mb-5 flex gap-1 border-b border-gray-200 dark:border-gray-700">
            <Link
                v-for="(label, key) in sections"
                :key="key"
                :href="`?section=${key}&window=${window}`"
                class="-mb-px border-b-2 px-3.5 py-2 text-sm font-medium no-underline"
                :class="key === section ? 'border-blue-500 text-gray-900 dark:text-white' : 'border-transparent text-gray-500'"
            >{{ label }}</Link>
        </nav>

        <!-- ───────────── Overview ───────────── -->
        <template v-if="section === 'overview'">
            <div class="mb-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <Widget title="Requests">
                    <div class="text-2xl font-semibold tabular-nums">{{ fmt(requestCount) }}</div>
                    <div class="mt-0.5 text-xs text-gray-500">in this window</div>
                    <div v-if="overview && overview.latencySeries.length" class="mt-2 h-10">
                        <Chart :points="overview.countSeries" :height="40" />
                    </div>
                </Widget>
                <Widget title="p50 latency">
                    <div class="text-2xl font-semibold tabular-nums" :style="{ color: tone(metrics.p50) }">{{ ms(metrics.p50) }}</div>
                    <div class="mt-0.5 text-xs text-gray-500">median (histogram)</div>
                </Widget>
                <Widget title="p95 latency">
                    <div class="text-2xl font-semibold tabular-nums" :style="{ color: tone(metrics.p95) }">{{ ms(metrics.p95) }}</div>
                    <div class="mt-0.5 text-xs text-gray-500">slowest 5%</div>
                </Widget>
                <Widget title="p99 latency">
                    <div class="text-2xl font-semibold tabular-nums" :style="{ color: tone(metrics.p99) }">{{ ms(metrics.p99) }}</div>
                    <div class="mt-0.5 text-xs text-gray-500">slowest 1%</div>
                </Widget>
            </div>

            <Panel v-if="overview && overview.latencySeries.length" heading="Avg latency over time" class="mb-4">
                <div class="h-36 px-1 py-2"><Chart :points="overview.latencySeries" :height="140" /></div>
            </Panel>

            <div class="grid gap-4 lg:grid-cols-2">
                <Panel v-if="metrics.routes.length" heading="Requests by route">
                    <Table>
                        <TableColumns><TableColumn>Route</TableColumn><TableColumn>Requests</TableColumn></TableColumns>
                        <TableRows>
                            <TableRow v-for="r in metrics.routes" :key="r.route">
                                <TableCell class="break-all">{{ r.route }}</TableCell>
                                <TableCell class="tabular-nums">{{ fmt(r.count) }}</TableCell>
                            </TableRow>
                        </TableRows>
                    </Table>
                </Panel>

                <Panel v-if="overview && overview.recent.length" heading="Recent requests">
                    <Table>
                        <TableColumns><TableColumn>When</TableColumn><TableColumn>Request</TableColumn><TableColumn>Duration</TableColumn></TableColumns>
                        <TableRows>
                            <TableRow v-for="t in overview.recent" :key="t.id">
                                <TableCell class="whitespace-nowrap text-gray-500">{{ time(t.timestampMs) }}</TableCell>
                                <TableCell><Link :href="`?trace=${t.id}`" class="no-underline hover:underline">{{ t.name }}</Link></TableCell>
                                <TableCell class="tabular-nums" :style="{ color: tone(t.durationMs) }">{{ ms(t.durationMs) }}</TableCell>
                            </TableRow>
                        </TableRows>
                    </Table>
                </Panel>
            </div>

            <Panel v-if="isOverviewEmpty" heading="No data">
                <p class="p-6 text-center text-gray-500">No requests in this window. Check the telemetry backend is reachable and receiving traces and metrics.</p>
            </Panel>
        </template>

        <!-- ───────────── Statamic ───────────── -->
        <template v-else>
            <div class="mb-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <Widget title="Static cache hit ratio">
                    <div class="text-2xl font-semibold tabular-nums" :style="{ color: ratioTone }">{{ metrics.cacheHitRatio === null ? '—' : Math.round(metrics.cacheHitRatio * 100) + '%' }}</div>
                    <div class="mt-0.5 text-xs text-gray-500">served from cache</div>
                </Widget>
                <Widget title="Stache warms">
                    <div class="text-2xl font-semibold tabular-nums">{{ fmt(metrics.stacheWarms) }}</div>
                    <div class="mt-0.5 text-xs text-gray-500">p95 warm {{ metrics.stacheWarmP95 ? ms(metrics.stacheWarmP95) : '—' }}</div>
                </Widget>
                <Widget title="Glide generations">
                    <div class="text-2xl font-semibold tabular-nums">{{ fmt(glideTotal) }}</div>
                    <div class="mt-0.5 text-xs text-gray-500">{{ fmt(metrics.glideClears) }} cache clears</div>
                </Widget>
                <Widget title="Form submissions">
                    <div class="text-2xl font-semibold tabular-nums">{{ fmt(formsTotal) }}</div>
                    <div class="mt-0.5 text-xs text-gray-500">{{ fmt(changesTotal) }} content changes</div>
                </Widget>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <Panel v-if="cacheOps.length" heading="Static cache operations">
                    <div class="flex flex-wrap gap-2 p-4">
                        <Badge v-for="[op, n] in cacheOps" :key="op" :text="`${op} ${fmt(n)}`" color="default" />
                    </div>
                </Panel>

                <Panel v-if="metrics.contentChanges.length" heading="Content changes">
                    <Table>
                        <TableColumns><TableColumn>Type</TableColumn><TableColumn>Action</TableColumn><TableColumn>Count</TableColumn></TableColumns>
                        <TableRows>
                            <TableRow v-for="c in metrics.contentChanges" :key="c.type + c.action">
                                <TableCell>{{ c.type }}</TableCell>
                                <TableCell><Badge :text="c.action" color="blue" size="sm" /></TableCell>
                                <TableCell class="tabular-nums">{{ fmt(c.count) }}</TableCell>
                            </TableRow>
                        </TableRows>
                    </Table>
                </Panel>

                <Panel v-if="metrics.glide.length" heading="Glide by preset">
                    <Table>
                        <TableColumns><TableColumn>Preset</TableColumn><TableColumn>Images</TableColumn></TableColumns>
                        <TableRows>
                            <TableRow v-for="g in metrics.glide" :key="g.preset"><TableCell>{{ g.preset }}</TableCell><TableCell class="tabular-nums">{{ fmt(g.count) }}</TableCell></TableRow>
                        </TableRows>
                    </Table>
                </Panel>

                <Panel v-if="metrics.forms.length" heading="Form submissions">
                    <Table>
                        <TableColumns><TableColumn>Form</TableColumn><TableColumn>Submissions</TableColumn></TableColumns>
                        <TableRows>
                            <TableRow v-for="f in metrics.forms" :key="f.form"><TableCell>{{ f.form }}</TableCell><TableCell class="tabular-nums">{{ fmt(f.count) }}</TableCell></TableRow>
                        </TableRows>
                    </Table>
                </Panel>
            </div>

            <Panel v-if="!hasStatamicData" heading="No data">
                <p class="p-6 text-center text-gray-500">No Statamic metrics in this window yet. They fill as the cache, Stache, Glide, forms and content are used (needs a shared TELEMETRY_STORE + telemetry:flush).</p>
            </Panel>
        </template>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { Header, Widget, Panel, Badge, Table, TableColumns, TableColumn, TableRows, TableRow, TableCell } from '@statamic/cms/ui';
import { Head, Link, router } from '@statamic/cms/inertia';
import Chart from '../components/Chart.vue';

const props = defineProps({
    section: String,
    sections: Object,
    window: String,
    windows: Object,
    overview: Object,
    metrics: Object,
});

const fmt = (n) => new Intl.NumberFormat().format(Math.round(n || 0));
const ms = (v) => (!v || v <= 0 ? '—' : v >= 1000 ? (v / 1000).toFixed(2) + ' s' : Math.round(v) + ' ms');
const tone = (v) => (v >= 1000 ? 'var(--color-red-500)' : v >= 300 ? 'var(--color-orange-500)' : 'var(--color-green-500)');
const time = (ts) => new Date(ts).toLocaleTimeString();

const requestCount = computed(() => props.metrics.requestTotal || props.overview?.count || 0);
const isOverviewEmpty = computed(() => requestCount.value <= 0 && !props.overview?.recent?.length && !props.metrics.routes.length);

const cacheOps = computed(() => Object.entries(props.metrics.cacheOperations || {}));
const glideTotal = computed(() => (props.metrics.glide || []).reduce((s, g) => s + g.count, 0));
const formsTotal = computed(() => (props.metrics.forms || []).reduce((s, f) => s + f.count, 0));
const changesTotal = computed(() => (props.metrics.contentChanges || []).reduce((s, c) => s + c.count, 0));
const hasStatamicData = computed(() => cacheOps.value.length || props.metrics.stacheWarms > 0 || glideTotal.value > 0 || formsTotal.value > 0 || changesTotal.value > 0);
const ratioTone = computed(() => {
    const r = props.metrics.cacheHitRatio;
    if (r === null) return 'var(--color-gray-500)';
    return r >= 0.8 ? 'var(--color-green-500)' : r >= 0.4 ? 'var(--color-orange-500)' : 'var(--color-red-500)';
});

function changeWindow(value) {
    router.get(location.pathname, { section: props.section, window: value }, { preserveScroll: true, preserveState: false });
}
</script>
