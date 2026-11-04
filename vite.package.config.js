import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    publicDir: false,
    plugins: [tailwindcss()],
    build: {
        outDir: 'packages/laravel-erd/dist',
        emptyOutDir: true,
        lib: {
            entry: 'packages/laravel-erd/resources/js/erd.js',
            name: 'LaravelErd',
            formats: ['iife'],
            fileName: () => 'erd.js',
            cssFileName: 'erd',
        },
    },
});
