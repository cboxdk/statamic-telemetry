// Number/time formatting for the telemetry CP, mirroring laravel-telemetry-ui's
// format helpers so counts, durations and percentages read the same everywhere.

const trim = (n) => Math.round(n * 10) / 10;

/** Compact count: 1.2k, 3.4M, 1.1Bn. */
export function count(n) {
    if (n == null || Number.isNaN(n)) return '—';
    const a = Math.abs(n);
    if (a >= 1e9) return trim(n / 1e9) + 'Bn';
    if (a >= 1e6) return trim(n / 1e6) + 'M';
    if (a >= 1e3) return trim(n / 1e3) + 'k';
    if (Number.isInteger(n)) return String(n);
    if (a < 1) return a < 0.01 ? '<0.01' : String(trim(n * 100) / 100);
    return String(trim(n));
}

/** Duration from milliseconds: 820µs, 48ms, 1.24s, 3.1m. */
export function ms(v) {
    if (v == null || v <= 0) return '—';
    if (v >= 60000) return trim(v / 60000) + ' m';
    if (v >= 1000) return (v / 1000).toFixed(2) + ' s';
    if (v >= 100) return Math.round(v) + ' ms';
    if (v >= 1) return trim(v) + ' ms';
    return Math.round(v * 1000) + ' µs';
}

/** Short axis duration (no spaces): 820µs, 48ms, 1.2s. */
export function msTight(v) {
    if (v == null) return '';
    if (v >= 60000) return trim(v / 60000) + 'm';
    if (v >= 1000) return trim(v / 1000) + 's';
    if (v >= 1) return Math.round(v) + 'ms';
    return Math.round(v * 1000) + 'µs';
}

/** Percentage from a 0–1 ratio: 0%, <0.1%, 0.4%, 12%. */
export function percent(ratio) {
    if (ratio == null || ratio <= 0) return '0%';
    const p = ratio * 100;
    if (p < 0.1) return '<0.1%';
    return (p >= 10 ? Math.round(p) : trim(p)) + '%';
}

/** HH:mm in the viewer's timezone. */
export function clock(msEpoch) {
    return new Date(msEpoch).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}
