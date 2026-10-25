<template>
    <div v-if="hasData" class="grid gap-6 lg:grid-cols-2">
        <!-- Queue jobs -->
        <Card class="flex flex-col gap-4">
            <div class="flex items-start justify-between gap-3">
                <h3 class="text-sm font-semibold">Queue jobs</h3>
                <span class="text-xs text-gray-500">processed per step</span>
            </div>
            <div class="grid grid-cols-[repeat(auto-fit,minmax(6.5rem,1fr))] gap-3">
                <Tile label="Processed" :value="fmt(jobs.processed)" />
                <Tile label="Dispatched" :value="fmt(jobs.dispatched)" dim />
                <Tile label="p95 duration" :value="ms(jobs.durationP95)" :tone="toneClass(jobs.durationP95)" />
                <Tile v-if="jobs.utilization != null" label="Workers busy" :value="Math.round(jobs.utilization) + '%'" dim />
            </div>
            <div class="h-32">
                <TimeChart v-if="jobsHas" :time="toMs(jobs.series)" :series="jobsSeries" :markers="markers" unit="count" :legend="false" :height="128" />
                <Empty v-else>No jobs in this window.</Empty>
            </div>
        </Card>

        <!-- Database queries -->
        <Card class="flex flex-col gap-4">
            <div class="flex items-start justify-between gap-3">
                <h3 class="text-sm font-semibold">Database queries</h3>
                <span v-if="queries.byConnection.length" class="text-xs text-gray-500">{{ queries.byConnection.map((c) => c.connection).join(', ') }}</span>
            </div>
            <div class="grid grid-cols-[repeat(auto-fit,minmax(6.5rem,1fr))] gap-3">
                <Tile label="Queries" :value="fmt(queries.total)" />
                <Tile label="N+1" :value="fmt(queries.nPlusOne)" :tone="queries.nPlusOne > 0 ? 'text-red-500' : ''" :dim="queries.nPlusOne === 0" />
                <Tile label="Duplicated" :value="fmt(queries.duplicated)" :tone="queries.duplicated > 0 ? 'text-amber-600 dark:text-amber-400' : ''" :dim="queries.duplicated === 0" />
            </div>
            <div class="h-32">
                <TimeChart v-if="queriesHas" :time="toMs(queries.series)" :series="queriesSeries" :markers="markers" unit="count" :legend="false" :height="128" />
                <Empty v-else>No queries in this window.</Empty>
            </div>
        </Card>

        <!-- Jobs by type -->
        <CardPanel v-if="jobs.byJob.length" heading="Jobs by type" :subheading="`p95 ${ms(jobs.durationP95)} across all jobs`" class="mb-0! lg:col-span-2">
            <Table>
                <TableColumns><TableColumn>Job</TableColumn><TableColumn>Queue</TableColumn><TableColumn>Processed</TableColumn><TableColumn>p95</TableColumn></TableColumns>
                <TableRows>
                    <TableRow v-for="(j, i) in jobs.byJob" :key="i">
                        <TableCell class="font-mono text-xs">{{ j.job }}</TableCell>
                        <TableCell><Badge :text="j.queue" size="sm" /></TableCell>
                        <TableCell class="tabular-nums">{{ fmt(j.count) }}</TableCell>
                        <TableCell class="tabular-nums" :class="toneClass(j.p95)">{{ ms(j.p95) }}</TableCell>
                    </TableRow>
                </TableRows>
            </Table>
        </CardPanel>

        <!-- Storage -->
        <CardPanel v-if="storage.operations.length" heading="Storage operations" class="mb-0!">
            <Table>
                <TableColumns><TableColumn>Disk</TableColumn><TableColumn>Operation</TableColumn><TableColumn>Count</TableColumn><TableColumn>Share</TableColumn></TableColumns>
                <TableRows>
                    <TableRow v-for="(o, i) in storage.operations" :key="i">
                        <TableCell>{{ o.disk }}</TableCell>
                        <TableCell><Badge :text="o.operation" color="blue" size="sm" /></TableCell>
                        <TableCell class="tabular-nums">{{ fmt(o.count) }}</TableCell>
                        <TableCell>
                            <div class="flex items-center gap-2">
                                <div class="h-1.5 w-24 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-800">
                                    <div class="h-full rounded-full bg-blue-500" :style="{ width: Math.round(o.share * 100) + '%' }" />
                                </div>
                                <span class="text-xs tabular-nums text-gray-500">{{ pct(o.share) }}</span>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableRows>
            </Table>
        </CardPanel>

        <!-- Outgoing HTTP -->
        <CardPanel v-if="http.byHost.length" heading="Outgoing HTTP" :subheading="`${fmt(http.total)} requests · ${pct(http.errorRate)} errors · p95 ${ms(http.p95)}`" class="mb-0!" :class="{ 'lg:col-span-2': !storage.operations.length }">
            <Table>
                <TableColumns><TableColumn>Host</TableColumn><TableColumn>Requests</TableColumn><TableColumn>Errors</TableColumn><TableColumn>p95</TableColumn></TableColumns>
                <TableRows>
                    <TableRow v-for="(h, i) in http.byHost" :key="i">
                        <TableCell class="break-all">{{ h.host }}</TableCell>
                        <TableCell class="tabular-nums">{{ fmt(h.count) }}</TableCell>
                        <TableCell class="tabular-nums" :class="h.errors > 0 ? 'text-red-500' : ''">{{ fmt(h.errors) }}</TableCell>
                        <TableCell class="tabular-nums" :class="toneClass(h.p95)">{{ ms(h.p95) }}</TableCell>
                    </TableRow>
                </TableRows>
            </Table>
        </CardPanel>
    </div>

    <Card v-else>
        <p class="py-10 text-center text-sm text-gray-500">No application activity in this window yet — queue, database, storage and outgoing-HTTP metrics fill as the app does that work (needs a shared TELEMETRY_STORE + telemetry:flush).</p>
    </Card>
</template>

<script setup>
import { computed, defineComponent, h } from 'vue';
import { Badge, Card, CardPanel, Table, TableColumns, TableColumn, TableRows, TableRow, TableCell } from '@statamic/cms/ui';
import Tile from './Tile.vue';
import TimeChart from './TimeChart.vue';
import { count as fmt, ms, percent as pct } from '../lib/format.js';

const props = defineProps({
    activity: { type: Object, required: true },
    markers: { type: Array, default: () => [] },
});

const Empty = defineComponent({
    setup(_, { slots }) {
        return () => h('div', { class: 'flex h-full items-center justify-center text-xs text-gray-500' }, h('p', { class: 'text-center' }, slots.default?.()));
    },
});

const jobs = computed(() => props.activity.jobs);
const queries = computed(() => props.activity.queries);
const storage = computed(() => props.activity.storage);
const http = computed(() => props.activity.http);

const toMs = (series) => (series?.t || []).map((t) => t * 1000);
const has = (series) => (series?.values || []).some((v) => v > 0);
const jobsHas = computed(() => has(jobs.value.series));
const queriesHas = computed(() => has(queries.value.series));
// Stable series objects (a new literal per render would re-draw the chart).
const jobsSeries = computed(() => [{ name: 'Jobs', data: jobs.value.series?.values || [], color: 'var(--color-green-500)', type: 'bar' }]);
const queriesSeries = computed(() => [{ name: 'Queries', data: queries.value.series?.values || [], color: 'var(--color-blue-500)', area: true }]);

const toneClass = (v) => (v >= 1000 ? 'text-red-500' : v >= 300 ? 'text-orange-500' : 'text-green-600 dark:text-green-400');
const hasData = computed(() => jobs.value.processed > 0 || queries.value.total > 0 || storage.value.total > 0 || http.value.total > 0);
</script>
