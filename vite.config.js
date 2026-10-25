import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import statamic from '@statamic/cms/vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/cp.js'],
            publicDirectory: 'resources/dist',
        }),
        tailwindcss(),
        statamic(),
    ],
});
