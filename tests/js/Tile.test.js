import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import Tile from '../../resources/js/components/Tile.vue';

describe('Tile', () => {
    it('shows label, value, delta and sub', () => {
        const w = mount(Tile, { props: { label: 'Requests', value: '1.2k', delta: '+12% vs previous', sub: 'served' } });
        expect(w.text()).toContain('REQUESTS'.toLowerCase() === 'requests' ? 'Requests' : 'Requests');
        expect(w.text()).toContain('1.2k');
        expect(w.text()).toContain('+12% vs previous');
        expect(w.text()).toContain('served');
        expect(w.find('a').exists()).toBe(false);
    });

    it('is a link when given an href and shows a dash for a missing value', () => {
        const w = mount(Tile, { props: { label: '5XX', value: null, href: '/cp/telemetry/explore?status=5xx' } });
        expect(w.find('a[data-stub="link"]').attributes('href')).toBe('/cp/telemetry/explore?status=5xx');
        expect(w.text()).toContain('—');
    });
});
