import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // Ponto de entrada próprio do aplicativo do técnico (Plano 12,
                // Task 12.6), fora do bundle Inertia do painel web acima: o
                // aplicativo tem ciclo de vida offline e não pode carregar o
                // painel inteiro só para mostrar a carga do dia.
                'resources/js/app-tecnico.js',
            ],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        VitePWA({
            // O aplicativo do técnico é servido em `/app`, na raiz do site, e
            // não em `/build/` (onde o Vite normalmente escreve os arquivos
            // gerados). Um service worker registrado a partir de `/build/sw.js`
            // só pode controlar páginas dentro de `/build/` e nunca alcançaria
            // `/app`. `outDir: 'public'` grava `sw.js` na raiz pública;
            // `scope: '/app'` restringe o controle do service worker só ao
            // aplicativo do técnico, para que ele nunca intercepte requisição
            // do painel administrativo (`/dashboard`, `/clients` etc.), mesmo
            // que os dois rodem no mesmo navegador.
            outDir: 'public',
            base: '/',
            buildBase: '/',
            scope: '/app',
            filename: 'sw.js',

            // O manifesto é um arquivo estático próprio, criado à mão em
            // `public/manifest.webmanifest`, e não gerado por este plugin.
            manifest: false,

            // `prompt`, nunca `autoUpdate`: atualizar sozinho no meio de uma
            // ordem de serviço em execução descartaria o estado da tela. A
            // nova versão fica esperando e o técnico decide quando aplicar,
            // pelo aviso próprio que `app-tecnico.js` exibe.
            registerType: 'prompt',

            // O registro do service worker é feito à mão dentro de
            // `app-tecnico.js` (via `virtual:pwa-register`), para controlar o
            // aviso de atualização. Sem injeção automática de script.
            injectRegister: false,

            workbox: {
                // Os arquivos de build (JS/CSS) continuam em `public/build`,
                // separados de onde o service worker é escrito (acima).
                globDirectory: fileURLToPath(new URL('./public/build', import.meta.url)),
                globPatterns: ['**/*.{js,css}'],

                // Sem fallback de navegação para "index.html": este projeto é
                // Laravel Blade e não tem esse arquivo. A navegação para
                // `/app` offline é resolvida pela regra de runtimeCaching
                // abaixo, e não por este mecanismo.
                navigateFallback: undefined,

                runtimeCaching: [
                    {
                        // Casca do aplicativo: a própria página `/app`,
                        // renderizada pelo Blade. Tenta a rede primeiro, com
                        // um teto curto, e cai para a última versão em cache
                        // quando não há rede - é o que garante abrir sem erro
                        // em modo avião.
                        urlPattern: ({ request, sameOrigin }) => sameOrigin && request.mode === 'navigate',
                        handler: 'NetworkFirst',
                        options: {
                            cacheName: 'app-tecnico-casca',
                            networkTimeoutSeconds: 3,
                            // Só guarda resposta de verdade: uma falha do
                            // servidor (500, 419 etc.) não pode virar a "última
                            // versão boa" da casca offline.
                            cacheableResponse: { statuses: [0, 200] },
                        },
                    },
                    {
                        // `/api/app/*` nunca é cacheado pelo service worker. O
                        // dado offline vive no IndexedDB (Task 12.7), com
                        // controle próprio de validade; cache de resposta de
                        // API aqui criaria uma segunda fonte da verdade.
                        urlPattern: ({ url }) => url.pathname.startsWith('/api/app/'),
                        handler: 'NetworkOnly',
                    },
                    {
                        // Foto e logo: cache-first, com teto de 50 itens.
                        urlPattern: ({ request, sameOrigin }) => sameOrigin && request.destination === 'image',
                        handler: 'CacheFirst',
                        options: {
                            cacheName: 'app-tecnico-imagens',
                            expiration: {
                                maxEntries: 50,
                            },
                            cacheableResponse: { statuses: [0, 200] },
                        },
                    },
                ],
            },
        }),
    ],
    resolve: {
        alias: {
            vue: 'vue/dist/vue.esm-bundler.js',
        },
    },
});
