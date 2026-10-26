<template>
    <div class="flex h-full min-h-0 flex-col">
        <!-- Header -->
        <div class="space-y-2 border-b border-gray-200 px-5 py-4 dark:border-gray-800">
            <div v-if="loading && !trace" class="space-y-2">
                <Skeleton class="h-5 w-2/3" />
                <Skeleton class="h-3 w-1/3" />
            </div>
            <template v-else-if="trace && trace.root">
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :text="statusText" :color="statusColor" size="sm" />
                    <h2 class="truncate font-mono text-sm font-semibold">{{ trace.root.name }}</h2>
                    <span class="ms-auto font-mono text-sm tabular-nums" :class="toneClass(trace.root.durationMs)">{{ ms(trace.root.durationMs) }}</span>
                </div>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
                    <span class="font-mono">trace {{ id.slice(0, 16) }}</span>
                    <button type="button" class="hover:underline" @click="copy(id)">Copy ID</button>
                    <span v-if="trace.services.length">· {{ trace.services.join(' › ') }}</span>
                    <span v-if="trace.spans.length">· {{ trace.spans.length }} spans</span>
                </div>
                <!-- Where to go from here: the page it served, every request like it, the entry behind it. -->
                <div class="flex flex-wrap items-center gap-2 pt-1">
                    <Button v-if="rootAttrs['http.route']" size="xs" :href="pageHref('requests', { route: rootAttrs['http.route'] })" :text="`Requests for ${rootAttrs['http.route']}`" />
                    <Button v-if="rootAttrs['url.path']" size="xs" :href="pageHref('explore', { signal: 'requests', path: rootAttrs['url.path'] })" :text="`Explore ${rootAttrs['url.path']}`" />
                    <Button v-if="rootAttrs['statamic.content.edit_url']" size="xs" :href="rootAttrs['statamic.content.edit_url']" text="Edit entry" />
                    <Button v-if="rootAttrs['statamic.content.url']" size="xs" variant="ghost" :href="rootAttrs['statamic.content.url']" target="_blank" text="View page" />
                </div>
            </template>
            <div v-else class="text-sm text-gray-500">Trace {{ id.slice(0, 16) }}</div>
        </div>

        <!-- Body -->
        <div class="min-h-0 flex-1 overflow-y-auto">
            <div v-if="loading && !trace" class="space-y-2 p-5">
                <Skeleton v-for="i in 6" :key="i" class="h-6 w-full" />
            </div>

            <!-- Logs only earn a tab when the backend linked some to this trace. -->
            <Waterfall v-else-if="trace && trace.spans.length && !trace.logs.length" :spans="trace.spans" />

            <div v-else-if="trace && trace.spans.length">
                <Tabs v-model="tab">
                    <TabList class="px-5">
                        <TabTrigger name="waterfall" :text="`Waterfall · ${trace.spans.length}`" />
                        <TabTrigger name="logs" :text="`Logs · ${trace.logs.length}`" />
                    </TabList>
                    <TabContent name="waterfall">
                        <Waterfall :spans="trace.spans" />
                    </TabContent>
                    <TabContent name="logs">
                        <div v-if="!trace.logs.length" class="p-8 text-center text-sm text-gray-500">No log lines carry this trace id.</div>
                        <div v-else class="divide-y divide-gray-200 dark:divide-gray-800">
                            <div v-for="(l, i) in trace.logs" :key="i" class="flex items-start gap-3 px-5 py-2 text-xs">
                                <span class="w-16 shrink-0 tabular-nums text-gray-500">{{ clock(l.timestamp) }}</span>
                                <Badge :text="l.level" :color="levelColor(l.level)" size="sm" />
                                <span class="min-w-0 flex-1 break-words font-mono">{{ l.line }}</span>
                            </div>
                        </div>
                    </TabContent>
                </Tabs>
            </div>

            <div v-else class="space-y-3 p-8 text-center text-sm text-gray-500">
                <p v-if="retrying">The trace hasn't sealed yet — retrying…</p>
                <template v-else>
                    <p>No spans for this trace. Very recent traces seal after about a minute; older ones may be outside the backend's retention.</p>
                    <Button size="sm" text="Retry" @click="load(true)" />
                </template>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { Badge, Button, Skeleton, Tabs, TabList, TabTrigger, TabContent } from '@statamic/cms/ui';
import Waterfall from './Waterfall.vue';
import { ms, clock } from '../lib/format.js';
import { telemetryUrl, currentTime } from '../lib/cp.js';

const props = defineProps({ id: { type: String, required: true } });

const trace = ref(null);
const loading = ref(false);
const retrying = ref(false);
const tab = ref('waterfall');

// The trace JSON lives at <cp>/telemetry/trace/{id} — resolved from the CP's own
// URL, so the drawer works from an entry editor as well as a telemetry page.
const endpoint = (id) => telemetryUrl(`trace/${encodeURIComponent(id)}`);

async function load(manual = false) {
    loading.value = true;
    try {
        const res = await fetch(endpoint(props.id), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        trace.value = res.ok ? await res.json() : { id: props.id, root: null, spans: [], logs: [], services: [] };
    } catch {
        trace.value = { id: props.id, root: null, spans: [], logs: [], services: [] };
    } finally {
        loading.value = false;
    }

    // Unsealed? Try once more shortly — telemetryd seals segments after ~1 min.
    if (!manual && trace.value.spans.length === 0 && !retrying.value) {
        retrying.value = true;
        setTimeout(async () => { await load(true); retrying.value = false; }, 4000);
    }
}

onMounted(load);
watch(() => props.id, () => { trace.value = null; retrying.value = false; load(); });

// The request span's attributes — what the trace was about. The root is the span
// the controller named as such (shallowest, longest), not just the first at depth 0.
const rootAttrs = computed(() => {
    const spans = trace.value?.spans || [];
    const root = spans.find((s) => s.depth === 0 && s.name === trace.value?.root?.name) || spans.find((s) => s.depth === 0);
    return root?.attributes || {};
});
// Links to the telemetry pages carry the current time state along.
const pageHref = (page, params) => telemetryUrl(page, { ...currentTime(), ...params });

const statusText = computed(() => (trace.value?.root?.error ? 'ERROR' : trace.value?.root?.status || 'OK'));
const statusColor = computed(() => {
    const s = Number(trace.value?.root?.status);
    if (trace.value?.root?.error || s >= 500) return 'red';
    if (s >= 400) return 'amber';
    return 'green';
});
const toneClass = (v) => (v >= 1000 ? 'text-red-500' : v >= 300 ? 'text-orange-500' : 'text-green-500');
const levelColor = (l) => ({ error: 'red', critical: 'red', alert: 'red', warning: 'orange', warn: 'orange', notice: 'blue', info: 'blue', debug: 'default' }[(l || '').toLowerCase()] || 'default');
const copy = (text) => navigator.clipboard?.writeText(text);
</script>
