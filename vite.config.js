import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

const devOrigin = process.env.VITE_DEV_ORIGIN;

const APP_PORT_ORIGIN = /^https?:\/\/[^/]+:8000$/;

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    build: {
        chunkSizeWarningLimit: 1000,
    },
    server: {
        host: devOrigin ? '0.0.0.0' : 'localhost',
        port: 5173,
        origin: devOrigin,
        hmr: devOrigin ? { host: new URL(devOrigin).hostname } : undefined,
        allowedHosts: devOrigin ? true : undefined,
        cors: devOrigin
            ? { origin: [APP_PORT_ORIGIN, /^https?:\/\/(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/] }
            : undefined,
        watch: {
            usePolling: process.env.VITE_POLLING === '1',
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
