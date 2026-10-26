import { defineComponent, h } from 'vue';

// What the tests can observe of the Inertia router: the calls made to it.
export const calls = [];
export const router = {
    on: () => () => {},
    get: (url, data, options) => calls.push({ method: 'get', url, data, options }),
    replace: (options) => calls.push({ method: 'replace', ...options }),
    reload: (options) => calls.push({ method: 'reload', options }),
};
export const Link = defineComponent({
    props: { href: { type: String, default: null } },
    setup: (props, { slots }) => () => h('a', { href: props.href, 'data-stub': 'link' }, slots.default?.()),
});
export const Head = defineComponent({ setup: () => () => null });
export const usePoll = () => ({ start() {}, stop() {} });
export const usePage = () => ({ props: {} });
