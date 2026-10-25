// Where the Control Panel lives — so links and fetches work from any CP page
// (an entry editor as much as a telemetry page) and under any CP route prefix.

export const cpUrl = () => String(window.Statamic?.$config?.get('cpUrl') || '/cp').replace(/\/$/, '');

const clean = (obj) => Object.fromEntries(Object.entries(obj || {}).filter(([, v]) => v !== null && v !== undefined && v !== ''));

/** `telemetryUrl('requests', { route })` → `/cp/telemetry/requests?route=…` */
export function telemetryUrl(path = '', params = {}) {
    const qs = new URLSearchParams(clean(params)).toString();
    return `${cpUrl()}/telemetry${path ? '/' + path : ''}${qs ? '?' + qs : ''}`;
}

/** The time state of the current page, to carry into a link elsewhere. */
export function currentTime() {
    const q = Object.fromEntries(new URLSearchParams(location.search));
    return clean({ window: q.window, from: q.from, to: q.to });
}
