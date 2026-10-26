import { describe, it, expect, beforeEach } from 'vitest';
import { cpUrl, telemetryUrl, currentTime } from '../../resources/js/lib/cp.js';

describe('CP urls', () => {
    beforeEach(() => { delete window.Statamic; });

    it('falls back to /cp and honours the configured CP url', () => {
        expect(cpUrl()).toBe('/cp');
        window.Statamic = { $config: { get: (k) => (k === 'cpUrl' ? 'https://example.test/admin/' : null) } };
        expect(cpUrl()).toBe('https://example.test/admin');
        expect(telemetryUrl('requests', { route: 'entry:pages', empty: '' })).toBe('https://example.test/admin/telemetry/requests?route=entry%3Apages');
    });

    it('carries only the time state of the current page', () => {
        window.history.replaceState({}, '', '/cp/x?window=6h&from=1&signal=logs');
        expect(currentTime()).toEqual({ window: '6h', from: '1' });
    });
});
