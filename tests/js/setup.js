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
