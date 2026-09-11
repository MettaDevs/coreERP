/**
 * React hanya boleh termuat sekali dalam satu bundel.
 *
 * Ini kriteria keluar fase 4, dan ia perlu pemeriksa sendiri karena kegagalannya tidak
 * berbunyi: dua salinan React membuat build tetap hijau, halaman tetap tampil, lalu setiap
 * hook melempar "Invalid hook call" hanya pada komponen yang kebetulan melintasi batas
 * salinan. Selama UI module berada di dalam iframe, dua salinan memang selalu ada — satu di
 * shell dan satu lagi di dalam bingkai — dan itulah yang dihapus fase ini.
 *
 * **Cara memeriksanya, dan kenapa bukan dengan menghitung nama berkas.** Nama potongan
 * disusun alat pembangun dan berubah kapan saja. Yang diperiksa di sini isi potongannya:
 * setiap potongan yang membawa implementasi React memuat pesan galat ringkasnya
 * ("Minified React error"), dan potongan yang hanya *memakai* React mengimpornya dari
 * potongan lain. Jadi di antara potongan yang membawa penanda itu, tepat satu yang tidak
 * mengimpor potongan React mana pun — itulah salinan React yang sesungguhnya. Dua berarti
 * ada dua salinan.
 *
 * Pemeriksa ini dibuktikan bisa merah dengan menggandakan React dengan sengaja; caranya
 * ditulis pada catatan pelaksanaan F4-10.
 */

import { readdirSync, readFileSync } from 'node:fs';
// `process` diimpor, bukan diambil sebagai global: setelan ESLint di sini tidak
// menyatakan lingkungan Node untuk berkas skrip, jadi globalnya dilaporkan tidak
// dikenal. Impor eksplisit lebih baik daripada melonggarkan setelannya untuk satu berkas.
import { basename, join } from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const folderAset = fileURLToPath(
    new URL('../public/build/assets', import.meta.url),
);

/**
 * Penanda salinan React: deskripsi simbol elemen React, yang hanya ada di implementasi
 * React itu sendiri. Dua bentuk disebut karena namanya berganti antar versi mayor —
 * `react.element` sampai React 18, `react.transitional.element` sejak React 19 — dan
 * pemeriksa yang hanya mengenal satu bentuk akan diam pada versi berikutnya.
 */
const PENANDA_REACT = ['react.transitional.element', 'react.element'];

/** Impor ke potongan lain yang namanya diawali `react`, mis. `from"./react-CvQj5sbI.js"`. */
const IMPOR_REACT = /from\s*["']\.\/react[^"']*\.js["']/;

function berkasJs() {
    let isi;

    try {
        isi = readdirSync(folderAset);
    } catch {
        gagal([
            `Folder ${folderAset} tidak ada.`,
            'Jalankan `npm run build` lebih dulu; pemeriksa ini membaca hasil build, bukan sumbernya.',
        ]);
    }

    const js = isi.filter((nama) => nama.endsWith('.js'));

    if (js.length === 0) {
        gagal([
            `Tidak satu pun berkas .js di ${folderAset}.`,
            'Pemeriksaan yang tidak membaca apa pun selalu hijau, dan itu lebih berbahaya daripada tidak ada pemeriksaan.',
        ]);
    }

    return js;
}

function gagal(baris) {
    console.error(baris.join('\n'));
    process.exit(1);
}

const pembawa = [];
const pemakai = [];

for (const nama of berkasJs()) {
    const isi = readFileSync(join(folderAset, nama), 'utf8');

    if (!PENANDA_REACT.some((penanda) => isi.includes(penanda))) {
        continue;
    }

    if (IMPOR_REACT.test(isi)) {
        pemakai.push(nama);

        continue;
    }

    pembawa.push(nama);
}

if (pembawa.length === 0) {
    gagal([
        'Tidak satu pun potongan membawa React.',
        `Penanda yang dicari — ${PENANDA_REACT.map((p) => `"${p}"`).join(' atau ')} — tidak ditemukan di mana`,
        'pun, jadi pemeriksa ini sedang mengukur sesuatu yang lain.',
        'Kemungkinan besar penandanya berubah setelah React naik versi.',
        'Perbarui penandanya; jangan biarkan pemeriksa yang tidak menemukan apa-apa dianggap hijau.',
    ]);
}

if (pembawa.length > 1) {
    gagal([
        `React termuat ${pembawa.length} kali dalam satu bundel: ${pembawa.join(', ')}.`,
        '',
        'Dua salinan React membuat build tetap hijau dan halaman tetap tampil, lalu setiap hook',
        'melempar "Invalid hook call" pada komponen yang kebetulan melintasi batas salinan —',
        'kegagalan yang muncul jauh dari sebabnya.',
        '',
        'Yang biasanya menyebabkannya: sebuah dependensi membawa React sendiri, atau sebuah paket',
        'dalam repo dipasang di luar `node_modules` akar sehingga pendakian modulnya berakhir di',
        'salinan yang berbeda. Periksa `resolve.dedupe` pada `vite.config.ts`.',
    ]);
}

console.log(
    JSON.stringify({
        tool: 'periksa-bundel',
        result: 'passed',
        react: basename(pembawa[0]),
        pemakai: pemakai.length,
    }),
);
