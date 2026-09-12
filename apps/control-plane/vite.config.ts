import { fileURLToPath } from 'node:url';
import inertia from '@inertiajs/vite';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

// apps/pusat-admin/vite.config.ts -> apps/pusat-admin -> apps -> akar repo
const akarRepo = fileURLToPath(new URL('../..', import.meta.url));

/*
 * Sengaja lebih sedikit daripada `apps/core/vite.config.ts`.
 *
 * Tidak ada Wayfinder, tidak ada React Compiler, tidak ada pemindaian halaman module. Ketiganya
 * menjawab masalah yang tidak dimiliki konsol ini: ia punya tiga layar, nol module, dan pemakainya
 * segelintir operator. Menyalin konfigurasi tetangga hanya karena ia ada berarti mewarisi juga
 * setiap cara ia bisa gagal.
 *
 * SSR dimatikan karena repo ini tidak punya berkas entri SSR. Ketika tidak ada, plugin menuruni
 * daftar kandidatnya dan berhenti di entri klien, lalu menggantung satu menit penuh saat server
 * pengembangan menyala — dan gejalanya terlihat seperti masalah lain sama sekali.
 */
export default defineConfig({
    resolve: {
        // Satu salinan React saja. Ini bukan soal penemuan berkas melainkan soal sebuah
        // dependensi yang membawa salinannya sendiri, dan itu bisa terjadi kapan saja.
        dedupe: ['react', 'react-dom'],
    },
    server: {
        fs: {
            // `packages/ui` berada di luar akar proyek ini. Tanpa baris ini server pengembangan
            // menolak menyajikannya dengan 403 sementara `npm run build` tetap berhasil — gagal
            // hanya di satu dari dua jalur, dan itu yang paling lama dicari.
            allow: [akarRepo],
        },
        watch: {
            // `vendor/` tidak ada di daftar abaian bawaan Vite, padahal isinya belasan ribu berkas.
            // Memollnya tiap 100 ms membanjiri threadpool libuv dan membuat muat ulang panas
            // tampak mati padahal ia hanya kelaparan.
            ignored: ['**/vendor/**', '**/storage/**', '**/bootstrap/cache/**', '**/public/build/**'],
        },
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        inertia({ ssr: false }),
        react(),
        tailwindcss(),
    ],
});
