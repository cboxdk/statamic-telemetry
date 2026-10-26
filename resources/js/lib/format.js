// Number and time formatting shared by every telemetry component.
//
// Times honour one display zone for the whole UI: the browser's own zone by
// default, or UTC when the person picks it in the topbar (persisted in
// localStorage). Nothing here is reactive — a zone change reloads the page.

const ZONE_KEY = 'statamic-telemetry.tz';

export function count(n) {
    if (n == null || Number.isNaN(n)) return '—';
    if (n >= 1e6) return (n / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
    if (n >= 1e3) return (n / 1e3).toFixed(1).replace(/\.0$/, '') + 'k';
    return String(Math.round(n));
}

export function ms(v) {
    if (v == null || Number.isNaN(v)) return '—';
    if (v >= 1000) return (v / 1000).toFixed(2) + ' s';
    if (v >= 100) return Math.round(v) + ' ms';
    if (v >= 1) return v.toFixed(1) + ' ms';
    return Math.round(v * 1000) + ' µs';
}

export function msTight(v) {
    if (v == null) return '';
    if (v >= 1000) return (v / 1000).toFixed(1) + 's';
    if (v >= 10) return Math.round(v) + 'ms';
    return v.toFixed(1) + 'ms';
}

export function percent(ratio) {
    if (ratio == null || Number.isNaN(ratio)) return '—';
    const p = ratio * 100;
    return (p < 10 ? p.toFixed(1) : Math.round(p)) + '%';
}

// ── Time zone ─────────────────────────────────────────────────────────────────

/** 'local' or 'UTC' — what the person chose, local by default. */
export function timeZoneChoice() {
    try { return window.localStorage.getItem(ZONE_KEY) === 'UTC' ? 'UTC' : 'local'; } catch { return 'local'; }
}

export function setTimeZoneChoice(choice) {
    try { window.localStorage.setItem(ZONE_KEY, choice === 'UTC' ? 'UTC' : 'local'); } catch {}
}

/** The IANA zone passed to Intl — undefined means the browser's own. */
const zone = () => (timeZoneChoice() === 'UTC' ? 'UTC' : undefined);

/** The short name of the zone in use, e.g. "CET", "GMT+2" or "UTC" — for the topbar. */
export function timeZoneLabel(choice = timeZoneChoice()) {
    if (choice === 'UTC') return 'UTC';
    try {
        return new Intl.DateTimeFormat([], { timeZoneName: 'short' }).formatToParts(new Date()).find((p) => p.type === 'timeZoneName')?.value || 'local';
    } catch {
        return 'local';
    }
}

/** "14:05" */
export function clock(msEpoch) {
    return new Date(msEpoch).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', timeZone: zone() });
}

/** "14:05:09" — for log and audit rows, where seconds matter. */
export function clockSeconds(msEpoch) {
    return new Date(msEpoch).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: zone() });
}

/** "25 Oct 14:05" — for axes and tooltips that span more than a day. */
export function dateClock(msEpoch) {
    return new Date(msEpoch).toLocaleString([], { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', timeZone: zone() });
}

/** "25 Oct 2026, 14:05:09" — a full timestamp. */
export function dateTime(msEpoch) {
    return new Date(msEpoch).toLocaleString([], { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: zone() });
}

/** The right time label for a span: clock within a day and a half, date + clock beyond. */
export function timeLabel(msEpoch, spanMs) {
    return spanMs > 36 * 3600e3 ? dateClock(msEpoch) : clock(msEpoch);
}
