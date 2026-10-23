<template>
    <div>
        <Head title="Explore" />
        <Header title="Explore" icon="chart-monitoring-indicator">
            <select :value="window" @change="changeWindow($event.target.value)" class="h-9 rounded-lg border border-gray-300 bg-white px-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                <option v-for="(label, value) in windows" :key="value" :value="value">{{ label }}</option>
            </select>
        </Header>

        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <nav class="flex gap-1 rounded-lg bg-gray-500/10 p-1">
                <button
                    v-for="(label, key) in signals"
                    :key="key"
                    @click="changeSignal(key)"
                    class="rounded-md px-3 py-1 text-sm font-medium"
                    :class="key === signal ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-700 dark:text-white' : 'text-gray-500'"
                >{{ label }}</button>
            </nav>
            <div class="flex items-center gap-2">
                <input v-model="searchText" @keyup.enter="applySearch" type="search" placeholder="Search…" class="h-9 w-56 rounded-lg border border-gray-300 bg-white px-2.5 text-sm dark:border-gray-700 dark:bg-gray-800" />
                <Button size="sm" variant="ghost" @click="applySearch">Search</Button>
                <Button size="sm" :variant="tailing ? 'primary' : 'ghost'" @click="toggleTail">{{ tailing ? '● Live' : 'Live tail' }}</Button>
            </div>
        </div>

        <Panel inset>
            <SplitterGroup direction="horizontal" class="h-[32rem]">
                <SplitterPanel :default-size="45" :min-size="25">
                    <div class="h-full overflow-auto">
                        <div v-if="!items.length" class="p-10 text-center text-sm text-gray-500">Nothing in this window.</div>

                        <!-- requests / traces -->
                        <template v-else-if="isTrace">
                            <button v-for="t in items" :key="t.id" @click="select(t)" class="flex w-full items-center gap-3 border-b border-gray-200 px-4 py-2 text-left text-sm hover:bg-gray-500/5 dark:border-gray-800" :class="{ 'bg-blue-500/10': t.id === activeId }">
                                <span class="w-16 shrink-0 tabular-nums text-xs text-gray-500">{{ time(t.timestamp) }}</span>
                                <span class="flex-1 truncate">{{ t.name }}</span>
                                <span class="shrink-0 tabular-nums" :style="{ color: tone(t.durationMs) }">{{ ms(t.durationMs) }}</span>
                            </button>
                        </template>

                        <!-- logs / errors -->
                        <template v-else>
                            <button v-for="l in items" :key="l.id" @click="select(l)" class="flex w-full items-start gap-3 border-b border-gray-200 px-4 py-2 text-left text-sm hover:bg-gray-500/5 dark:border-gray-800" :class="{ 'bg-blue-500/10': l.id === activeId }">
                                <span class="w-16 shrink-0 tabular-nums text-xs text-gray-500">{{ time(l.timestamp) }}</span>
                                <Badge :text="l.level" :color="levelColor(l.level)" size="sm" />
                                <span class="flex-1 truncate font-mono text-xs">{{ l.line }}</span>
                            </button>
                        </template>
                    </div>
                </SplitterPanel>

                <SplitterResizeHandle class="w-px bg-gray-200 dark:bg-gray-700" />

                <SplitterPanel :min-size="30">
                    <div class="h-full overflow-auto">
                        <div v-if="!selected" class="flex h-full items-center justify-center text-sm text-gray-500">Select an item to inspect it.</div>

                        <!-- trace waterfall -->
                        <div v-else-if="isTrace">
                            <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                                <div class="truncate font-medium">{{ selected.name }}</div>
                                <div class="mt-0.5 text-xs text-gray-500"><code class="font-mono">{{ selected.id }}</code> · {{ ms(selected.durationMs) }}</div>
                            </div>
                            <div v-if="detail && detail.spans && detail.spans.length"><Waterfall :spans="detail.spans" /></div>
                            <div v-else class="p-8 text-center text-sm text-gray-500">No spans — the trace is outside the backend's retention window, or hasn't sealed yet.</div>
                        </div>

                        <!-- log detail -->
                        <div v-else class="p-4">
                            <div class="mb-3 flex items-center gap-2">
                                <Badge :text="selected.level" :color="levelColor(selected.level)" size="sm" />
                                <span class="text-xs text-gray-500">{{ new Date(selected.timestamp).toLocaleString() }}</span>
                            </div>
                            <pre class="mb-4 whitespace-pre-wrap break-words rounded-lg bg-gray-500/10 p-3 font-mono text-xs">{{ selected.line }}</pre>
                            <div class="grid grid-cols-[10rem_1fr] gap-x-3 gap-y-1 text-xs">
                                <template v-for="(v, k) in selected.labels" :key="k">
                                    <div class="truncate text-gray-500">{{ k }}</div>
                                    <div class="break-words font-mono">{{ v }}</div>
                                </template>
                            </div>
                        </div>
                    </div>
                </SplitterPanel>
            </SplitterGroup>
        </Panel>
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { Header, Panel, Badge, Button, SplitterGroup, SplitterPanel, SplitterResizeHandle } from '@statamic/cms/ui';
import { Head, router, usePoll } from '@statamic/cms/inertia';
import Waterfall from '../components/Waterfall.vue';

const props = defineProps({
    signal: String,
    signals: Object,
    window: String,
    windows: Object,
    q: String,
    selectedId: String,
    items: { type: Array, default: () => [] },
    detail: Object,
});

const searchText = ref(props.q || '');
const activeId = ref(props.selectedId || null);
const selectedLog = ref(null);
const tailing = ref(false);

const isTrace = computed(() => props.signal === 'requests' || props.signal === 'traces');
const selected = computed(() => {
    if (!activeId.value) return null;
    if (isTrace.value) return props.items.find((i) => i.id === activeId.value) || null;
    return selectedLog.value;
});

const ms = (v) => (!v || v <= 0 ? '—' : v >= 1000 ? (v / 1000).toFixed(2) + ' s' : Math.round(v) + ' ms');
const tone = (v) => (v >= 1000 ? 'var(--color-red-500)' : v >= 300 ? 'var(--color-orange-500)' : 'var(--color-green-500)');
const time = (ts) => new Date(ts).toLocaleTimeString();
const levelColor = (l) => ({ error: 'red', critical: 'red', alert: 'red', warning: 'orange', warn: 'orange', notice: 'blue', info: 'blue', debug: 'default' }[(l || '').toLowerCase()] || 'default');

const base = () => ({ signal: props.signal, window: props.window, q: searchText.value });
const go = (data, options = {}) => router.get(location.pathname, data, { preserveScroll: true, ...options });

function changeSignal(s) { activeId.value = null; selectedLog.value = null; go({ signal: s, window: props.window, q: searchText.value }); }
function changeWindow(w) { go({ ...base(), window: w }); }
function applySearch() { go(base()); }

function select(item) {
    activeId.value = item.id;
    if (isTrace.value) {
        go({ ...base(), id: item.id });
    } else {
        selectedLog.value = item;
    }
}

const poll = usePoll(2000, { preserveScroll: true }, { autoStart: false });
function toggleTail() {
    tailing.value = !tailing.value;
    tailing.value ? poll.start() : poll.stop();
}

watch(() => props.signal, () => { searchText.value = props.q || ''; });
</script>
