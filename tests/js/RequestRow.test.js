import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import RequestRow from '../../resources/js/components/RequestRow.vue';

const row = { id: 't1', name: 'GET entry:pages.page', method: 'GET', path: '/about', status: '200', title: 'About', collection: 'pages', cache: 'hit', durationMs: 42, timestamp: 1_700_000_000_000, editUrl: 'http://x/cp/collections/pages/entries/about' };

describe('RequestRow', () => {
    it('reads as the page that was hit', () => {
        const w = mount(RequestRow, { props: { row } });
        expect(w.text()).toContain('GET /about');
        expect(w.text()).toContain('About');
        expect(w.text()).toContain('entry:pages');
        expect(w.text()).toContain('cache hit');
        expect(w.text()).toContain('42.0 ms');
        expect(w.find('a[title="Edit this entry in the CP"]').attributes('href')).toBe(row.editUrl);
    });

    it('opens the trace on click and filters on its chips without opening', async () => {
        const w = mount(RequestRow, { props: { row } });
        await w.find('[role=button]').trigger('click');
        expect(w.emitted('open')).toEqual([['t1']]);
        await w.find('button[title="Click to filter by status"]').trigger('click');
        await w.find('button[title="Click to filter by collection"]').trigger('click');
        expect(w.emitted('facet')).toEqual([['status', '200'], ['collection', 'pages']]);
        expect(w.emitted('open')).toHaveLength(1);
    });

    it('falls back to the span name without a path, and hides Edit without an edit url', () => {
        const w = mount(RequestRow, { props: { row: { ...row, path: null, editUrl: null, title: null } } });
        expect(w.text()).toContain('GET entry:pages.page');
        expect(w.find('a').exists()).toBe(false);
    });
});
