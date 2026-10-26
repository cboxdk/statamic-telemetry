import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';

// Unit tests for the CP's JavaScript. The `@statamic/cms/*` modules resolve to
// the Control Panel's window globals at runtime; here they resolve to small
// stubs so components and helpers can be exercised on their own.
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@statamic/cms/inertia': fileURLToPath(new URL('./tests/js/stubs/inertia.js', import.meta.url)),
            '@statamic/cms/ui': fileURLToPath(new URL('./tests/js/stubs/ui.js', import.meta.url)),
            '@statamic/cms': fileURLToPath(new URL('./tests/js/stubs/cms.js', import.meta.url)),
        },
    },
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.js'],
        setupFiles: ['tests/js/setup.js'],
        globals: true,
    },
});
