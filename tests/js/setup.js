// jsdom's Web Storage needs an origin it does not always have under Vitest;
// the components only need a working get/set/clear, so provide one.
if (typeof window !== 'undefined' && !window.localStorage) {
    const store = new Map();
    Object.defineProperty(window, 'localStorage', {
        configurable: true,
        value: {
            getItem: (k) => (store.has(k) ? store.get(k) : null),
            setItem: (k, v) => store.set(k, String(v)),
            removeItem: (k) => store.delete(k),
            clear: () => store.clear(),
            get length() { return store.size; },
            key: (i) => [...store.keys()][i] ?? null,
        },
    });
}

// The CP's translator is a window global; the tests get a plain English one.
globalThis.__ = (key, replacements = {}) => Object.entries(replacements).reduce((out, [k, v]) => out.replaceAll(':' + k, String(v)), String(key));
globalThis.__n = (key, count, replacements = {}) => {
    const forms = String(key).split('|');
    return globalThis.__(count === 1 ? forms[0] : forms[forms.length - 1], { count, ...replacements });
};

// Templates resolve `__()` as a component property — the CP registers it as a
// Vue global property; mirror that for mounted components.
import { config } from '@vue/test-utils';
config.global.mocks = { ...(config.global.mocks || {}), __: globalThis.__, __n: globalThis.__n };
