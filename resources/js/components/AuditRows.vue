<template>
    <!-- The content-change trail: who changed what, when, and what it touched. -->
    <div v-if="result.error" class="border-b border-gray-200 bg-red-500/10 px-4 py-3 text-sm text-red-600 dark:border-gray-800 dark:text-red-400">{{ result.error }}</div>

    <div v-if="!result.rows.length" class="p-12 text-center text-sm text-gray-500">
        <template v-if="result.error">{{ __('Could not load the audit log.') }}</template>
        <template v-else-if="filtered">{{ __('No changes match these filters in this period.') }}</template>
        <template v-else>{{ __('No content changes in this period.') }}</template>
    </div>

    <div v-else class="px-4">
        <Table>
            <TableColumns>
                <TableColumn>{{ __('When') }}</TableColumn><TableColumn>{{ __('Action') }}</TableColumn><TableColumn>{{ __('Item') }}</TableColumn><TableColumn>{{ __('Changed') }}</TableColumn><TableColumn>{{ __('Who') }}</TableColumn>
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
        <div>{{ __n(':count change|:count changes', result.total, { count: fmt(result.total) }) }}</div>
        <div v-if="totalPages > 1" class="flex items-center gap-2">
            <Button size="sm" variant="ghost" :text="__('Previous')" :disabled="result.page <= 1" @click="$emit('page', result.page - 1)" />
            <span>{{ __('Page :page of :total', { page: result.page, total: totalPages }) }}</span>
            <Button size="sm" variant="ghost" :text="__('Next')" :disabled="result.page >= totalPages" @click="$emit('page', result.page + 1)" />
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { Badge, Button, Table, TableColumns, TableColumn, TableRows, TableRow, TableCell } from '@statamic/cms/ui';
import { count as fmt, dateClock } from '../lib/format.js';

const props = defineProps({
    result: { type: Object, required: true },
    filtered: { type: Boolean, default: false },
});
defineEmits(['page']);

const totalPages = computed(() => (props.result.perPage > 0 ? Math.max(1, Math.ceil(props.result.total / props.result.perPage)) : 1));

const color = (a) => ({ created: 'green', published: 'green', uploaded: 'green', updated: 'blue', unpublished: 'orange', unpublish: 'orange', replaced: 'orange', reuploaded: 'orange', restore: 'orange', deleted: 'red' }[a] || 'default');
const label = (r) => r.title || r.handle || r.id || '—';
const context = (r) => [r.type, r.scope, r.site].filter(Boolean).join(' · ');
const newTab = (url) => { try { return new URL(url).host !== location.host; } catch { return false; } };
const when = (ts) => dateClock(ts);
</script>
