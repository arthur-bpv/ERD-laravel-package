import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// Dentro do Docker o Vite precisa escutar em todas as interfaces e avisar ao
// navegador (que roda fora do container) qual endereco usar. O docker-compose
// define VITE_DEV_ORIGIN; rodando sem Docker nada disso muda.
const devOrigin = process.env.VITE_DEV_ORIGIN;

// A pagina vem da porta 8000 e o CSS/JS da 5173 — origens diferentes. O app.js
// e um ES module, e modules SEMPRE passam por CORS: sem liberar a origem da
// pagina, o navegador bloqueia o JS e nada de Livewire/Alpine/AlpineFlow inicia
// (o CSS ate carrega, porque <link rel=stylesheet> nao checa CORS — o sintoma
// enganoso e "a pagina tem estilo mas o board nao funciona").
//
// Liberamos QUALQUER host na porta 8000, em vez de um endereco fixo: assim tanto
// faz o dev abrir por localhost, por IP, pelo nome da maquina ou pela VPN.
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
        // O Vite recusa requisicoes de hosts que nao conhece. Quando o servidor
        // e acessado por IP ou nome de rede (nao "localhost"), precisamos liberar.
        allowedHosts: devOrigin ? true : undefined,
        // Libera a aplicacao (qualquer host na :8000) + o padrao do Vite (localhost).
        cors: devOrigin
            ? { origin: [APP_PORT_ORIGIN, /^https?:\/\/(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/] }
            : undefined,
        watch: {
            // usePolling: so no Windows/macOS, quando o hot reload nao percebe
            // as edicoes (VITE_POLLING=1 no docker-compose.yml).
            usePolling: process.env.VITE_POLLING === '1',
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
