import { describe, it, expect, beforeEach } from 'vitest';
import { parseDrawer, serializeDrawer, windowSeconds, rangeBounds, useTelemetryUrl } from '../../resources/js/lib/useTelemetryUrl.js';
import { calls } from './stubs/inertia.js';

describe('drawer stack in the URL', () => {
    it('round-trips trace and entity entries, bottom to top', () => {
        const stack = [{ kind: 'trace', id: 'abc123' }, { kind: 'entity', type: 'route', value: 'entry:pages/with:colon' }];
        const param = serializeDrawer(stack);
        expect(param).toBe('trace:abc123~entity:route:entry%3Apages%2Fwith%3Acolon');
        expect(parseDrawer(param)).toEqual(stack);
        expect(parseDrawer('')).toEqual([]);
    });
});

describe('windows', () => {
    it('reads m / h / d and falls back to an hour', () => {
        expect(windowSeconds('15m')).toBe(900);
        expect(windowSeconds('6h')).toBe(21600);
        expect(windowSeconds('7d')).toBe(604800);
        expect(windowSeconds('nonsense')).toBe(3600);
    });

    it('bounds a custom range by its from/to and a window by now', () => {
        expect(rangeBounds({ window: '1h', from: 100, to: 200 })).toEqual([100000, 200000]);
        const [start, end] = rangeBounds({ window: '1h' });
        expect(end - start).toBe(3600e3);
    });
});

describe('useTelemetryUrl', () => {
    beforeEach(() => {
        calls.length = 0;
        window.history.replaceState({}, '', '/cp/telemetry/explore?window=6h&signal=requests');
    });

    it('builds hrefs that carry the page state and drop empty values', () => {
        const { href } = useTelemetryUrl(() => ({ window: '6h', from: null, signal: 'requests' }));
        expect(href('/cp/telemetry/requests', { route: 'entry:pages' })).toBe('/cp/telemetry/requests?window=6h&signal=requests&route=entry%3Apages');
    });

    it('opens a drawer as a client-side URL change, not a server visit', () => {
        const { pushDrawer, drawers } = useTelemetryUrl(() => ({ window: '6h' }));
        pushDrawer({ kind: 'trace', id: 't1' });
        expect(calls.at(-1).method).toBe('replace');
        expect(calls.at(-1).url).toContain('drawer=trace%3At1');
        expect(drawers.value).toEqual([{ kind: 'trace', id: 't1' }]);
    });

    it('pops one level at a time', () => {
        window.history.replaceState({}, '', '/cp/telemetry/explore?drawer=trace%3Aa~trace%3Ab');
        window.dispatchEvent(new PopStateEvent('popstate')); // the mirror of location.search follows navigations, not replaceState
        const { popDrawer } = useTelemetryUrl();
        popDrawer();
        expect(calls.at(-1).url).toContain('drawer=trace%3Aa');
        expect(calls.at(-1).url).not.toContain('trace%3Ab');
    });
});
