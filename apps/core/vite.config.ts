import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

// apps/core/vite.config.ts -> apps/core -> apps -> akar repo
const akarRepo = fileURLToPath(new URL('../..', import.meta.url));
const folderModule = fileURLToPath(new URL('../../modules', import.meta.url));

// Origin yang boleh mengambil aset dari server pengembangan.
//
// Alamat aplikasi dibaca dari `APP_URL` bila ada, karena portanya dapat berbeda antar mesin.
const asalAplikasi = process.env.APP_URL?.replace(/\/+$/, '');
const asalYangDiizinkan = [
    ...(asalAplikasi ? [asalAplikasi] : []),
    'http://localhost:8000',
    'http://127.0.0.1:8000',
    'http://localhost:5173',
    'http://127.0.0.1:5173',
].filter((asal, indeks, semua) => semua.indexOf(asal) === indeks);

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
        watch: {
            /*
             * Yang dipantau dibatasi, dan ini bukan kerapian — tanpanya muat ulang panas
             * praktis tidak dapat dipakai.
             *
             * Daftar abaian bawaan Vite hanya memuat `.git`, `node_modules`, `test-results`,
             * dan folder cache. **`vendor/` tidak termasuk**, padahal di bawah folder app ini
             * ada belasan ribu berkas dan sebagian besar miliknya. Pada bind mount Windows,
             * inotify tidak merambat sehingga pengawasnya harus memoll; memoll belasan ribu
             * berkas tiap 100 ms membanjiri threadpool libuv yang hanya berisi empat utas.
             *
             * Akibatnya bukan kegagalan melainkan **kelaparan**: pembaruan tetap terkirim dan
             * tetap diterapkan, tetapi transform yang belum ter-cache mengantre di belakang
             * tumpukan `stat` dan baru selesai puluhan detik sampai beberapa menit kemudian.
             * Yang terlihat pengembang: menyimpan berkas, layar diam, lalu ia menekan muat
             * ulang — dan muat ulang terasa cepat justru karena antrean tadi sudah
             * menghangatkan cache-nya.
             *
             * Daftar di bawah **ditambahkan** ke bawaan Vite, bukan menggantikannya.
             */
            ignored: [
                '**/vendor/**',
                '**/storage/**',
                '**/bootstrap/cache/**',
                '**/public/build/**',
            ],
        },
        // Alamat yang **ditulis ke berkas `hot`**, dan karena itu alamat yang dituju peramban.
        //
        // Ia berbeda dari alamat yang didengarkan server. Di dalam container, Vite harus mengikat
        // `0.0.0.0` supaya dapat dihubungi dari luar container — dan tanpa baris ini Laravel
        // menuliskan `0.0.0.0` itu apa adanya ke berkas `hot`. Peramban tidak dapat menghubungi
        // `0.0.0.0`, jadi seluruh aset gagal dimuat meski setiap container sehat dan Vite menjawab
        // dengan benar pada porta yang sama.
        //
        // Kegagalannya karena itu tidak terlihat di mana pun kecuali di tab jaringan peramban.
        origin: process.env.VITE_ORIGIN,
        // Halaman disajikan dari porta aplikasi, asetnya dari porta Vite — dua origin berbeda,
        // jadi setiap permintaan aset adalah permintaan lintas origin.
        //
        // Sejak Vite membatasi ini secara bawaan, menyetel `origin` di atas saja justru membuat
        // Vite mengumumkan dirinya sendiri sebagai satu-satunya origin yang diizinkan — dan
        // peramban menolak seluruh skrip, gaya, serta font-nya. Container tetap sehat, `curl` ke
        // kedua porta tetap menjawab 200, dan halamannya kosong: kegagalan yang hanya terlihat di
        // konsol peramban.
        //
        // Yang diizinkan dibatasi ke mesin ini saja. Server pengembangan mengikat `0.0.0.0` di
        // dalam container, jadi membiarkannya memantulkan origin mana pun berarti halaman dari
        // mana pun boleh membaca kode sumber yang sedang dikerjakan.
        cors: {
            origin: asalYangDiizinkan,
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
        /*
         * SSR dimatikan, dan itu bukan sekadar mengikuti keadaan — ia memperbaiki kerusakan.
         *
         * Repo ini tidak punya berkas entri SSR. Ketika tidak ada, plugin menuruni daftar
         * kandidatnya dan berhenti di `resources/js/app.tsx` — entri **klien**. Ia lalu memuat
         * entri itu di lingkungan SSR saat server pengembangan menyala, tersangkut pada
         * `packages/ui/dist` yang berada di luar akar proyek, dan gagal setelah menunggu satu
         * menit penuh:
         *
         *     Failed to warm up Inertia SSR module graph: transport invoke timed out after 60000ms
         *
         * Selama satu menit itu server pengembangan berhenti menjawab. Gejalanya terlihat seperti
         * masalah lain sama sekali — aset gagal dimuat, muat ulang panas tidak sampai, halaman
         * kosong — dan berubah-ubah tergantung kapan sebuah permintaan kebetulan jatuh.
         *
         * Nyalakan lagi hanya bersamaan dengan berkas entri SSR yang sungguhan.
         */
        inertia({ ssr: false }),
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
