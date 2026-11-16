import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite-plus';

export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
        dedupe: ['react', 'react-dom'],
    },
    test: {
        environment: 'node',
        include: [
            'tests/Frontend/**/*.test.tsx',
            'apps/mobile/src/**/*.test.tsx',
        ],
    },
});
