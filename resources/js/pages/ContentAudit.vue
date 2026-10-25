<template>
    <TelemetryPage title="Content audit" icon="list-ul" :nav="nav" :timebar="false">
        <p class="-mt-2 text-sm text-gray-500">Every change to content and structure — who made it, what it touched, and what it did.</p>

        <Card inset>
            <div class="flex flex-wrap items-end gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                <label class="flex flex-col gap-1 text-xs font-medium text-gray-500">Type
                    <Select :model-value="filters.type" :options="options(result.types, query.type, 'All types')" size="sm" @update:model-value="(v) => set('type', v)" />
                </label>
                <label class="flex flex-col gap-1 text-xs font-medium text-gray-500">Action
                    <Select :model-value="filters.action" :options="options(result.actions, query.action, 'All actions')" size="sm" @update:model-value="(v) => set('action', v)" />
                </label>
                <label v-if="result.sites.length > 1 || query.site" class="flex flex-col gap-1 text-xs font-medium text-gray-500">Site
                    <Select :model-value="filters.site" :options="options(result.sites, query.site, 'All sites')" size="sm" @update:model-value="(v) => set('site', v)" />
                </label>
                <label class="flex flex-col gap-1 text-xs font-medium text-gray-500">Window
                    <Select :model-value="filters.window" :options="windowOptions" size="sm" @update:model-value="(v) => set('window', v)" />
                </label>
                <label class="flex min-w-56 flex-1 flex-col gap-1 text-xs font-medium text-gray-500">Search
                    <Input v-model="filters.q" type="search" placeholder="Title, id, editor, field…" @keyup.enter="apply" />
                </label>
                <Button variant="primary" text="Filter" @click="apply" />
            </div>

            <div v-if="result.error" class="border-b border-gray-200 bg-red-500/10 px-4 py-3 text-sm text-red-600 dark:border-gray-800 dark:text-red-400">{{ result.error }}</div>

            <div v-if="!result.rows.length" class="p-12 text-center text-sm text-gray-500">
                <template v-if="result.error">Could not load the audit log.</template>
                <template v-else-if="hasFilters">No changes match these filters in this period.</template>
                <template v-else>No content changes in this period. The audit log needs content events writing to the telemetry backend.</template>
            </div>

            <div v-else class="px-4">
                <Table>
                    <TableColumns>
                        <TableColumn>When</TableColumn><TableColumn>Action</TableColumn><TableColumn>Item</TableColumn><TableColumn>Changed</TableColumn><TableColumn>Who</TableColumn>
                    </TableColumns>
                    <TableRows>
                        <TableRow v-for="(row, i) in result.rows" :key="i">
                            <TableCell class="whitespace-nowrap tabular-nums text-gray-500">{{ when(row.timestamp) }}</TableCell>
                            <TableCell><Badge :text="row.action" :color="color(row.action)" size="sm" /></TableCell>
                            <TableCell>
                                <div class="font-medium">
                                    <a v-if="row.editUrl" :href="row.editUrl" :target="newTab(row.editUrl) ? '_blank' : null" class="no-underline hover:underline">{{ label(row) }}</a>
                                    <template v-else>{{ label(row) }}</template>
                                </div>
                                <div v-if="context(row)" class="mt-0.5 text-xs text-gray-500">{{ context(row) }}<template v-if="row.message"> — “{{ row.message }}”</template></div>
                            </TableCell>
                            <TableCell class="font-mono text-xs text-gray-500">{{ row.changed || '—' }}</TableCell>
                            <TableCell>
                                <div>{{ row.who || row.origin || '—' }}</div>
                                <div v-if="row.who && row.origin" class="text-xs text-gray-500">{{ row.origin }}</div>
                                <div v-else-if="row.command" class="text-xs text-gray-500">{{ row.command }}</div>
                            </TableCell>
                        </TableRow>
                    </TableRows>
                </Table>
            </div>

            <div v-if="result.rows.length" class="flex flex-wrap items-center justify-between gap-4 border-t border-gray-200 px-4 py-3 text-xs text-gray-500 dark:border-gray-800">
                <div>{{ fmt(result.total) }} {{ result.total === 1 ? 'change' : 'changes' }}</div>
                <div v-if="totalPages > 1" class="flex items-center gap-2">
                    <Button size="sm" variant="ghost" text="Previous" :disabled="result.page <= 1" @click="goto(result.page - 1)" />
                    <span>Page {{ result.page }} of {{ totalPages }}</span>
                    <Button size="sm" variant="ghost" text="Next" :disabled="result.page >= totalPages" @click="goto(result.page + 1)" />
                </div>
            </div>

            <div v-if="result.capped" class="border-t border-gray-200 bg-gray-500/5 px-4 py-2.5 text-xs text-gray-500 dark:border-gray-800">Showing the newest changes in this window — narrow the window to see further back.</div>
        </Card>
    </TelemetryPage>
</template>

<script setup>
import { computed, reactive } from 'vue';
import { Badge, Button, Card, Input, Select, Table, TableColumns, TableColumn, TableRows, TableRow, TableCell } from '@statamic/cms/ui';
import { router } from '@statamic/cms/inertia';
import TelemetryPage from '../components/TelemetryPage.vue';
import { count as fmt } from '../lib/format.js';

const props = defineProps({ nav: { type: Array, default: () => [] }, query: Object, result: Object, windows: Object });

const filters = reactive({
    type: props.query.type || '',
    action: props.query.action || '',
    site: props.query.site || '',
    window: props.query.window || '24h',
    q: props.query.search || '',
});

const hasFilters = computed(() => filters.type || filters.action || filters.site || filters.q);
const totalPages = computed(() => (props.result.perPage > 0 ? Math.max(1, Math.ceil(props.result.total / props.result.perPage)) : 1));
const windowOptions = computed(() => Object.entries(props.windows || {}).map(([value, label]) => ({ value, label })));

const options = (facet, active, allLabel) => {
    const set = new Set(facet);
    if (active) set.add(active);
    return [{ value: '', label: allLabel }, ...[...set].sort().map((v) => ({ value: v, label: v }))];
};

const color = (a) => ({ created: 'green', published: 'green', uploaded: 'green', updated: 'blue', unpublished: 'orange', unpublish: 'orange', replaced: 'orange', reuploaded: 'orange', restore: 'orange', deleted: 'red' }[a] || 'default');
const label = (r) => r.title || r.handle || r.id || '—';
const context = (r) => [r.type, r.scope, r.site].filter(Boolean).join(' · ');
const newTab = (url) => { try { return new URL(url).host !== location.host; } catch { return false; } };
const when = (ts) => {
    const d = new Date(ts);
    const p = (n) => String(n).padStart(2, '0');
    return `${p(d.getDate())}/${p(d.getMonth() + 1)} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
};

function set(key, value) {
    filters[key] = value ?? '';
    apply();
}
function apply(page = null) {
    router.get(location.pathname, { type: filters.type, action: filters.action, site: filters.site, window: filters.window, q: filters.q, ...(page ? { page } : {}) }, { preserveState: false, preserveScroll: true });
}
const goto = (page) => apply(page);
</script>
