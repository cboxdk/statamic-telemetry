<template>
    <div>
        <Head title="Content audit" />
        <Header title="Content audit" icon="list-ul" />
        <p class="mb-4 text-sm text-gray-500">Every change to content and structure — who made it, what it touched, and what it did.</p>

        <Panel>
            <div class="flex flex-wrap items-end gap-3 border-b border-gray-200 p-4 dark:border-gray-700">
                <label class="flex flex-col gap-1 text-xs font-medium text-gray-500">Type
                    <select v-model="filters.type" @change="apply" class="h-9 rounded-lg border border-gray-300 bg-white px-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                        <option value="">All types</option>
                        <option v-for="o in options(result.types, query.type)" :key="o" :value="o">{{ o }}</option>
                    </select>
                </label>
                <label class="flex flex-col gap-1 text-xs font-medium text-gray-500">Action
                    <select v-model="filters.action" @change="apply" class="h-9 rounded-lg border border-gray-300 bg-white px-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                        <option value="">All actions</option>
                        <option v-for="o in options(result.actions, query.action)" :key="o" :value="o">{{ o }}</option>
                    </select>
                </label>
                <label v-if="result.sites.length > 1 || query.site" class="flex flex-col gap-1 text-xs font-medium text-gray-500">Site
                    <select v-model="filters.site" @change="apply" class="h-9 rounded-lg border border-gray-300 bg-white px-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                        <option value="">All sites</option>
                        <option v-for="o in options(result.sites, query.site)" :key="o" :value="o">{{ o }}</option>
                    </select>
                </label>
                <label class="flex flex-col gap-1 text-xs font-medium text-gray-500">Window
                    <select v-model="filters.window" @change="apply" class="h-9 rounded-lg border border-gray-300 bg-white px-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                        <option v-for="(label, value) in windows" :key="value" :value="value">{{ label }}</option>
                    </select>
                </label>
                <label class="flex flex-1 flex-col gap-1 text-xs font-medium text-gray-500" style="min-width: 14rem">Search
                    <input v-model="filters.q" @keyup.enter="apply" type="search" placeholder="Title, id, editor, field…" class="h-9 rounded-lg border border-gray-300 bg-white px-2.5 text-sm dark:border-gray-700 dark:bg-gray-800" />
                </label>
                <Button variant="primary" @click="apply">Filter</Button>
            </div>

            <div v-if="result.error" class="border-b border-gray-200 bg-red-500/10 px-4 py-3 text-sm text-red-500 dark:border-gray-700">{{ result.error }}</div>

            <div v-if="!result.rows.length" class="p-12 text-center text-gray-500">
                <template v-if="result.error">Could not load the audit log.</template>
                <template v-else-if="hasFilters">No changes match these filters in this period.</template>
                <template v-else>No content changes in this period. The audit log needs content events writing to the telemetry backend.</template>
            </div>

            <Table v-else>
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

            <div v-if="result.rows.length" class="flex flex-wrap items-center justify-between gap-4 border-t border-gray-200 px-4 py-3 text-xs text-gray-500 dark:border-gray-700">
                <div>{{ fmt(result.total) }} {{ result.total === 1 ? 'change' : 'changes' }}</div>
                <div v-if="totalPages > 1" class="flex items-center gap-2">
                    <Button size="sm" variant="ghost" :disabled="result.page <= 1" @click="goto(result.page - 1)">Previous</Button>
                    <span>Page {{ result.page }} of {{ totalPages }}</span>
                    <Button size="sm" variant="ghost" :disabled="result.page >= totalPages" @click="goto(result.page + 1)">Next</Button>
                </div>
            </div>

            <div v-if="result.capped" class="border-t border-gray-200 bg-gray-500/5 px-4 py-2.5 text-xs text-gray-500 dark:border-gray-700">Showing the newest changes in this window — narrow the window to see further back.</div>
        </Panel>
    </div>
</template>

<script setup>
import { computed, reactive } from 'vue';
import { Header, Panel, Badge, Button, Table, TableColumns, TableColumn, TableRows, TableRow, TableCell } from '@statamic/cms/ui';
import { Head, router } from '@statamic/cms/inertia';

const props = defineProps({ query: Object, result: Object, windows: Object });

const filters = reactive({
    type: props.query.type || '',
    action: props.query.action || '',
    site: props.query.site || '',
    window: props.query.window || '24h',
    q: props.query.search || '',
});

const hasFilters = computed(() => filters.type || filters.action || filters.site || filters.q);
const totalPages = computed(() => (props.result.perPage > 0 ? Math.max(1, Math.ceil(props.result.total / props.result.perPage)) : 1));

const options = (facet, active) => {
    const set = new Set(facet);
    if (active) set.add(active);
    return [...set].sort();
};

const color = (a) => ({ created: 'green', published: 'green', uploaded: 'green', updated: 'blue', unpublished: 'orange', unpublish: 'orange', replaced: 'orange', reuploaded: 'orange', restore: 'orange', deleted: 'red' }[a] || 'default');
const label = (r) => r.title || r.handle || r.id || '—';
const context = (r) => [r.type, r.scope, r.site].filter(Boolean).join(' · ');
const fmt = (n) => new Intl.NumberFormat().format(n || 0);
const newTab = (url) => { try { return new URL(url).host !== location.host; } catch { return false; } };
const when = (ts) => {
    const d = new Date(ts);
    const p = (n) => String(n).padStart(2, '0');
    return `${p(d.getDate())}/${p(d.getMonth() + 1)} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
};

function apply() {
    router.get(location.pathname, { type: filters.type, action: filters.action, site: filters.site, window: filters.window, q: filters.q }, { preserveState: false, preserveScroll: true });
}
function goto(page) {
    router.get(location.pathname, { type: filters.type, action: filters.action, site: filters.site, window: filters.window, q: filters.q, page }, { preserveState: false, preserveScroll: true });
}
</script>
