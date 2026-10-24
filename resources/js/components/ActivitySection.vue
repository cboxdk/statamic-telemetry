<template>
    <div v-if="hasData">
        <!-- Headline cards: Jobs + Queries -->
        <div class="tel-grid" style="grid-template-columns: repeat(auto-fit, minmax(29rem, 1fr)); margin-bottom: 1rem">
            <div class="tel-card">
                <div class="tel-card__head">
                    <span class="tel-label">Queue jobs</span>
                    <span class="tel-sub" style="margin: 0">{{ fmt(jobs.dispatched) }} dispatched<template v-if="jobs.utilization != null"> · {{ Math.round(jobs.utilization) }}% workers</template></span>
                </div>
                <div class="tel-metric">{{ fmt(jobs.processed) }} <span class="tel-sub" style="display: inline">processed</span></div>
                <div style="margin-top: 0.75rem">
                    <TimeChart v-if="jobsHas" :time="toMs(jobs.series)" :series="[{ name: 'Jobs', data: jobs.series.values, color: 'var(--color-green-500)', type: 'bar' }]" :markers="markers" unit="count" :legend="false" :height="130" />
                    <div v-else class="tel-chart-empty" style="height: 130px">No jobs in this window.</div>
                </div>
            </div>

            <div class="tel-card">
                <div class="tel-card__head">
                    <span class="tel-label">Database queries</span>
                    <span v-if="queries.byConnection.length" class="tel-sub" style="margin: 0">{{ queries.byConnection.map((c) => c.connection).join(', ') }}</span>
                </div>
                <div class="tel-metric">{{ fmt(queries.total) }}</div>
                <div class="tel-chips" style="margin-top: 0.45rem">
                    <span class="tel-chip" :class="queries.nPlusOne > 0 ? 'tel-chip--red' : ''">N+1 {{ fmt(queries.nPlusOne) }}</span>
                    <span class="tel-chip" :class="queries.duplicated > 0 ? 'tel-chip--amber' : ''">Duplicated {{ fmt(queries.duplicated) }}</span>
                </div>
                <div style="margin-top: 0.6rem">
                    <TimeChart v-if="queriesHas" :time="toMs(queries.series)" :series="[{ name: 'Queries', data: queries.series.values, color: 'var(--color-blue-500)', area: true }]" :markers="markers" unit="count" :legend="false" :height="104" />
                </div>
            </div>
        </div>

        <!-- Jobs by type -->
        <div v-if="jobs.byJob.length" class="tel-panel" style="margin-bottom: 1rem">
            <div class="tel-panel__head">Jobs by type<span class="tel-sub" style="display: inline; margin-left: 0.4rem">p95 {{ ms(jobs.durationP95) }}</span></div>
            <Table>
                <TableColumns><TableColumn>Job</TableColumn><TableColumn>Queue</TableColumn><TableColumn>Processed</TableColumn><TableColumn>p95</TableColumn></TableColumns>
                <TableRows>
                    <TableRow v-for="(j, i) in jobs.byJob" :key="i">
                        <TableCell class="font-mono text-xs">{{ j.job }}</TableCell>
                        <TableCell><Badge :text="j.queue" color="default" size="sm" /></TableCell>
                        <TableCell class="tel-num">{{ fmt(j.count) }}</TableCell>
                        <TableCell class="tel-num" :style="{ color: tone(j.p95) }">{{ ms(j.p95) }}</TableCell>
                    </TableRow>
                </TableRows>
            </Table>
        </div>

        <!-- Storage + Outgoing HTTP -->
        <div class="tel-grid tel-grid--wide">
            <div v-if="storage.operations.length" class="tel-panel">
                <div class="tel-panel__head">Storage operations</div>
                <Table>
                    <TableColumns><TableColumn>Disk</TableColumn><TableColumn>Operation</TableColumn><TableColumn>Count</TableColumn><TableColumn>Share</TableColumn></TableColumns>
                    <TableRows>
                        <TableRow v-for="(o, i) in storage.operations" :key="i">
                            <TableCell>{{ o.disk }}</TableCell>
                            <TableCell><Badge :text="o.operation" color="blue" size="sm" /></TableCell>
                            <TableCell class="tel-num">{{ fmt(o.count) }}</TableCell>
                            <TableCell>
                                <div style="display: flex; align-items: center; gap: 0.5rem">
                                    <div class="tel-sharebar"><div class="tel-sharebar__fill" :style="{ width: Math.round(o.share * 100) + '%' }"></div></div>
                                    <span class="tel-num tel-sub" style="margin: 0">{{ pct(o.share) }}</span>
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableRows>
                </Table>
            </div>

            <div v-if="http.byHost.length" class="tel-panel">
                <div class="tel-panel__head">
                    Outgoing HTTP
                    <span class="tel-sub" style="display: inline; margin-left: 0.4rem">{{ fmt(http.total) }} · {{ pct(http.errorRate) }} errors · p95 {{ ms(http.p95) }}</span>
                </div>
                <Table>
                    <TableColumns><TableColumn>Host</TableColumn><TableColumn>Requests</TableColumn><TableColumn>Errors</TableColumn><TableColumn>p95</TableColumn></TableColumns>
                    <TableRows>
                        <TableRow v-for="(h, i) in http.byHost" :key="i">
                            <TableCell class="break-all">{{ h.host }}</TableCell>
                            <TableCell class="tel-num">{{ fmt(h.count) }}</TableCell>
                            <TableCell class="tel-num" :style="{ color: h.errors > 0 ? 'var(--color-red-500)' : 'inherit' }">{{ fmt(h.errors) }}</TableCell>
                            <TableCell class="tel-num" :style="{ color: tone(h.p95) }">{{ ms(h.p95) }}</TableCell>
                        </TableRow>
                    </TableRows>
                </Table>
            </div>
        </div>
    </div>

    <div v-else class="tel-panel">
        <p class="tel-empty">No application activity in this window yet — queue, database, storage and outgoing-HTTP metrics fill as the app does that work (needs a shared TELEMETRY_STORE + telemetry:flush).</p>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { Badge, Table, TableColumns, TableColumn, TableRows, TableRow, TableCell } from '@statamic/cms/ui';
import TimeChart from './TimeChart.vue';
import { count as fmt, ms, percent as pct } from '../lib/format.js';

const props = defineProps({
    activity: { type: Object, required: true },
    markers: { type: Array, default: () => [] },
});

const jobs = computed(() => props.activity.jobs);
const queries = computed(() => props.activity.queries);
const storage = computed(() => props.activity.storage);
const http = computed(() => props.activity.http);

const toMs = (series) => (series?.t || []).map((t) => t * 1000);
const has = (series) => (series?.values || []).some((v) => v > 0);
const jobsHas = computed(() => has(jobs.value.series));
const queriesHas = computed(() => has(queries.value.series));

const tone = (v) => (v >= 1000 ? 'var(--color-red-500)' : v >= 300 ? 'var(--color-orange-500)' : 'var(--color-green-500)');

const hasData = computed(() => jobs.value.processed > 0 || queries.value.total > 0 || storage.value.total > 0 || http.value.total > 0);
</script>
