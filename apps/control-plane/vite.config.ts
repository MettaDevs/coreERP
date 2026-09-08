import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

// apps/control-plane/vite.config.ts -> apps/control-plane -> apps -> akar repo
const akarRepo = fileURLToPath(new URL('../..', import.meta.url));
const folderModule = fileURLToPath(new URL('../../modules', import.meta.url));

export default defineConfig({
    resolve: {
        /*
         * `@apperp/ui` ikut di sini, dan bukan demi menghemat ukuran bundel.
         *
         * Halaman module berada di luar folder proyek ini, sehingga pencarian
         * `node_modules` dari berkasnya menaiki folder sampai akar repo — tempat yang
         * tidak punya `node_modules`. Akibatnya `npm run build` berhenti dengan
         * "Rolldown failed to resolve import "@apperp/ui/table"". `dedupe` menyuruh Vite
         * menyelesaikan paket ini dari akar proyek, jadi peta `exports` paketnya tetap
         * dipakai apa adanya — berbeda dengan alias, yang akan melewatinya dan menuntut
         * jalur `dist/` ditulis tangan.
         */
        dedupe: ['react', 'react-dom', '@apperp/ui'],
        alias: {
            // Halaman module hidup di luar akar proyek ini. Alias dipakai kode yang
            // menyebut satu berkas module secara langsung; pemindaian folder di
            // `resources/js/app.tsx` tetap memakai pola relatif, karena pola glob
            // diselesaikan saat membangun dan bentuk relatif yang pasti dikenali.
            '@modules': folderModule,
        },
    },
    server: {
        fs: {
            // Tanpa baris ini server pengembangan menolak menyajikan berkas di luar akar
            // proyek dengan 403, sementara `npm run build` tetap berhasil. Kegagalan yang
            // hanya muncul di satu dari dua jalur adalah yang paling lama dicari.
            allow: [akarRepo],
        },
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
            command: process.env.WAYFINDER_COMMAND,
        }),
    ],
});
