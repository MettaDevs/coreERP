import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

// apps/control-plane/vite.config.ts -> apps/control-plane -> apps -> akar repo
const akarRepo = fileURLToPath(new URL('../..', import.meta.url));
const folderModule = fileURLToPath(new URL('../../modules', import.meta.url));

export default defineConfig({
    resolve: {
        /*
         * `@apperp/ui` pernah ikut di daftar ini. Sebabnya: halaman module berada di luar
         * folder proyek ini, sehingga pencarian `node_modules` dari berkasnya menaiki
         * folder sampai akar repo — tempat yang saat itu tidak punya `node_modules` —
         * dan `npm run build` berhenti dengan "Rolldown failed to resolve import
         * "@apperp/ui/table"".
         *
         * Akar repo sekarang akar workspace npm, jadi pendakian itu berakhir di
         * `node_modules` yang benar dan penyebutan paket itu tidak perlu ditolong lagi.
         * `react` dan `react-dom` tetap di sini: keduanya bukan soal penemuan berkas
         * melainkan soal satu salinan React, dan itu masih bisa pecah kapan saja sebuah
         * dependensi membawa salinannya sendiri.
         */
        dedupe: ['react', 'react-dom'],
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
            /*
             * Tidak ada blok `fonts` di sini, dan itu disengaja.
             *
             * Cetakan Laravel memasang `bunny('Instrument Sans')`, yang **mengunduh font dari
             * fonts.bunny.net setiap kali build berjalan**. Akibatnya build ini gagal ketika
             * host itu lambat — sudah terjadi di CI dengan `ConnectTimeoutError` pada
             * fonts.bunny.net:443, dan tidak ada satu pun barisnya yang salah.
             *
             * Fontnya sendiri tidak pernah dipakai: tema memakai Poppins dan Geist, keduanya
             * di-import dari paket `@fontsource` di `packages/ui/src/styles.css` sehingga ikut
             * terpasang lewat npm dan tidak menyentuh jaringan saat build.
             *
             * Kalau kelak ada font baru, ambil paket `@fontsource`-nya. Build yang menghubungi
             * internet berarti build yang bisa gagal karena server orang lain, dan itu berlaku
             * juga di server pelanggan yang memasang sendiri.
             */
        }),
        inertia(),
        react({
            /*
             * `packages/ui/dist` adalah keluaran `tsc`, bukan sumber yang ditulis orang.
             * Selama `@apperp/ui` dipasang dari berkas `.tgz`, isinya berada di
             * `node_modules` dan plugin ini melewatinya secara bawaan. Sebagai workspace
             * ia keluar dari `node_modules`, jadi tanpa baris ini React Compiler ikut
             * menggarapnya: bundel bertambah 3.067 bytes yang seluruhnya jatuh di 14
             * potongan yang memuat komponen paket ini. Pengecualian ini menjaga bundel
             * tetap sama seperti sebelum pemindahan; menjalankan compiler di atas
             * keluaran build adalah keputusan tersendiri, bukan efek samping pengemasan.
             */
            exclude: [/[\\/]packages[\\/]ui[\\/]dist[\\/]/],
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
