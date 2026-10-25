// The one URL/state model for every telemetry page.
//
// Window / custom range (`window`, `from`, `to`), the Explore query and the
// drawer stack all live in the URL, so every link, tab and action goes through
// here and nothing is dropped between pages. The drawer stack mirrors
// telemetry-ui: `?drawer=trace:<id>~entity:<type>:<value>` (bottom → top).

import { computed, reactive } from 'vue';
import { router } from '@statamic/cms/inertia';

// Reactive mirror of location.search, kept fresh across Inertia navigations.
const current = reactive({ search: typeof location !== 'undefined' ? location.search : '' });
let listening = false;
function listen() {
    if (listening || typeof window === 'undefined') return;
    listening = true;
    const sync = () => (current.search = location.search);
    router.on('navigate', sync);
    router.on('success', sync);
    window.addEventListener('popstate', sync);
}

const clean = (obj) =>
    Object.fromEntries(Object.entries(obj).filter(([, v]) => v !== null && v !== undefined && v !== ''));

export const DRAWER_SEP = '~';

/** Parse `trace:abc~entity:route:/cp` into [{kind, id, value?}, …]. */
export function parseDrawer(param) {
    if (!param) return [];
    return param
        .split(DRAWER_SEP)
        .filter(Boolean)
        .map((entry) => {
            const [kind, ...rest] = entry.split(':');
            if (kind === 'entity') {
                const [type, ...value] = rest;
                return { kind, type, value: decodeURIComponent(value.join(':')) };
            }
            return { kind, id: rest.join(':') };
        });
}

export function serializeDrawer(stack) {
    return stack
        .map((e) => (e.kind === 'entity' ? `entity:${e.type}:${encodeURIComponent(e.value)}` : `${e.kind}:${e.id}`))
        .join(DRAWER_SEP);
}

/**
 * @param {() => object} carry  the page's own carried params (window, from, to,
 *   signal, filters, groupBy, …) — read lazily so they track props.
 */
export function useTelemetryUrl(carry = () => ({})) {
    listen();

    const query = computed(() => Object.fromEntries(new URLSearchParams(current.search)));
    const carried = () => clean(carry());

    /** An href to another telemetry page, carrying the time state. */
    const href = (path, extra = {}) => {
        const params = new URLSearchParams(clean({ ...carried(), ...extra }));
        const qs = params.toString();
        return qs ? `${path}?${qs}` : path;
    };

    /** Navigate within the current page with the carried params + overrides. */
    const go = (extra = {}, options = {}) =>
        router.get(location.pathname, clean({ ...carried(), ...extra }), { preserveScroll: true, ...options });

    // ── Drawer stack ──────────────────────────────────────────────────────────
    const drawers = computed(() => parseDrawer(query.value.drawer));
    const topDrawer = computed(() => drawers.value[drawers.value.length - 1] ?? null);

    /**
     * Rewrite the URL's drawer state. Drawers fetch their own data, so this is
     * a client-side visit (no server round trip — the dashboard would otherwise
     * recompute ~20 PromQL queries just to open a panel). `only` opts into a
     * partial reload for pages whose props depend on the drawer.
     */
    const setDrawerUrl = (params, only) => {
        const url = href(location.pathname, params);
        if (only.length) {
            return router.get(location.pathname, clean(params), { preserveScroll: true, preserveState: true, replace: true, only });
        }
        router.replace({ url, preserveScroll: true, preserveState: true });
        current.search = new URL(url, location.origin).search;
    };

    /** Push a drawer entry on top of the stack. */
    const pushDrawer = (entry, { only = [], extra = {} } = {}) => {
        const stack = [...drawers.value];
        const top = stack[stack.length - 1];
        if (!(top && serializeDrawer([top]) === serializeDrawer([entry]))) stack.push(entry);
        return setDrawerUrl({ ...query.value, ...carried(), ...extra, drawer: serializeDrawer(stack.slice(-8)) }, only);
    };

    /** Replace the whole stack with one entry (row clicks, j/k stepping). */
    const replaceDrawer = (entry, { only = [], extra = {} } = {}) =>
        setDrawerUrl({ ...query.value, ...carried(), ...extra, drawer: serializeDrawer([entry]) }, only);

    /** Pop the top drawer (Esc / close). */
    const popDrawer = () => {
        const stack = drawers.value.slice(0, -1);
        return setDrawerUrl({ ...query.value, ...carried(), drawer: stack.length ? serializeDrawer(stack) : null }, []);
    };

    const closeDrawers = () => setDrawerUrl({ ...query.value, ...carried(), drawer: null }, []);

    return { query, href, go, drawers, topDrawer, pushDrawer, replaceDrawer, popDrawer, closeDrawers };
}

/** The window in seconds for a `15m` / `6h` / `7d` label (mirror of TimeRange). */
export function windowSeconds(window) {
    const m = /^(\d+)([mhd])$/.exec(window || '1h');
    if (!m) return 3600;
    return +m[1] * (m[2] === 'd' ? 86400 : m[2] === 'h' ? 3600 : 60);
}

/** [startMs, endMs] for the page's range props. */
export function rangeBounds({ window, from, to }) {
    if (to) return [from * 1000, to * 1000];
    const end = Date.now();
    return [end - windowSeconds(window) * 1000, end];
}
