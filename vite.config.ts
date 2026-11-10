import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    build: {
        // Los chunks grandes son librerías que solo se descargan en la
        // pantalla que las usa (ApexCharts, hls.js, pdf.js) y el núcleo de
        // la app (~920 kB, ~196 kB gzip). El aviso por defecto (500 kB) no
        // aporta nada aquí; sí debe avisar si algo rebasa 1 MB.
        chunkSizeWarningLimit: 1000,
        rolldownOptions: {
            // Informe de cuánto tarda cada plugin: es informativo y depende
            // de la máquina; no es un problema del build.
            checks: { pluginTimings: false },
            // reka-ui trae su propia copia de @vueuse/core con comentarios
            // /* #__PURE__ */ mal colocados: es de un tercero y no afecta el
            // resultado. Solo se calla ese aviso y solo en node_modules.
            onLog(level, log, handler) {
                if (
                    log.code === 'INVALID_ANNOTATION' &&
                    (log.id ?? log.message ?? '').includes('node_modules')
                ) {
                    return;
                }

                handler(level, log);
            },
            output: {
                // El worker de pdf.js viene como .mjs y muchos Nginx lo
                // sirven como application/octet-stream: el navegador lo
                // bloquea y ningún visor PDF carga. Con extensión .js se
                // sirve como JavaScript en cualquier servidor.
                assetFileNames: (asset) =>
                    (asset.names?.[0] ?? '').endsWith('.mjs')
                        ? 'assets/[name]-[hash].js'
                        : 'assets/[name]-[hash][extname]',
            },
        },
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
                // Tipografía de marca (PEOPLE): títulos y encabezados.
                bunny('Montserrat', {
                    weights: [500, 600, 700],
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
            // Mismo resultado con o sin base de datos (GenerarWayfinderCommand).
            command: 'php artisan people:wayfinder',
        }),
    ],
});
