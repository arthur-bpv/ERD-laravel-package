import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
    build: {
        // Livewire + Alpine vêm empacotados juntos e precisam carregar no boot,
        // então o bundle naturalmente passa de 500 kB. Subimos o limite do aviso.
        chunkSizeWarningLimit: 800,
    },
});
