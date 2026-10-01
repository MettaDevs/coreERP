// Menahan `phpstan-baseline.neon` bertambah.
//
// Baseline adalah daftar error yang sengaja dibungkam. Ia sah sebagai titik awal — memperbaiki
// ratusan temuan sekaligus bukan pekerjaan yang bisa diselesaikan satu pull request — tetapi ia
// hanya berguna bila arahnya satu: **menyusut**. Baseline yang boleh bertambah bukan lagi utang
// yang sedang dicicil, melainkan tempat menyembunyikan temuan baru.
//
// Aturannya sudah tertulis sejak lama. Yang tidak ada adalah mesin yang menegakkannya: sampai
// pemeriksaan ini dibuat, tidak satu pun test atau langkah alur membandingkan isinya, jadi pull
// request yang menambah bungkaman tetap hijau dan hanya tertangkap kalau peninjau kebetulan
// membuka berkas itu.
//
// Yang dibandingkan **bukan totalnya**, melainkan setiap entri satu per satu.
//
// Membandingkan total adalah rancangan pertama skrip ini, dan ia terbukti tidak menjaga apa pun:
// pada cabang yang membuang tujuh belas bungkaman, menambahkan tiga bungkaman baru masih terbaca
// "menyusut" dan lolos. Siapa pun yang memperbaiki satu hal karena itu boleh menyelipkan
// bungkaman baru secara gratis — persis celah yang hendak ditutup.
//
// Jadi yang berlaku: **tidak boleh ada bungkaman baru**. Setiap entri yang ada sekarang wajib
// sudah ada pada pembandingnya, dengan `count:` yang tidak lebih besar. Entri dikenali dari
// gabungan path, identifier, dan pesannya. Membuang entri tetap bebas, dan memang itu arah yang
// diinginkan.
//
// Pemakaian:
//   node scripts/periksa-baseline-phpstan.mjs [ref-pembanding]
//
// Ref pembandingnya `origin/main` bila tidak disebut. Bila ref itu tidak ada — checkout dangkal
// tanpa `main`, atau repo yang baru dibuat — langkah ini berkata terus terang bahwa ia tidak
// dapat membandingkan, dan **tidak** berpura-pura lulus.

import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const akar = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const jalurBaseline = 'apps/core/phpstan-baseline.neon';
const pembanding = process.argv[2] ?? 'origin/main';

function gagal(...baris) {
    for (const b of baris) {
        console.error(b);
    }

    process.exit(1);
}

/**
 * Membaca baseline menjadi peta `kunci entri` ke `count`.
 *
 * Kuncinya gabungan path, identifier, dan pesan — ketiganya diperlukan, karena satu berkas dapat
 * membungkam beberapa jenis temuan sekaligus dan satu identifier dapat muncul di banyak berkas.
 */
function bacaEntri(isi) {
    const entri = new Map();
    let kini = null;

    const simpan = () => {
        if (kini && kini.message && kini.path) {
            const kunci = `${kini.path}|${kini.identifier ?? ''}|${kini.message}`;
            entri.set(kunci, (entri.get(kunci) ?? 0) + (kini.count ?? 1));
        }
    };

    for (const baris of isi.split('\n')) {
        if (/^\s*-\s*$/.test(baris)) {
            simpan();
            kini = {};
            continue;
        }

        if (!kini) {
            continue;
        }

        const cocok = /^\s*(message|identifier|count|path):\s*(.*?)\s*$/.exec(baris);

        if (cocok) {
            const [, kunci, nilai] = cocok;
            kini[kunci] = kunci === 'count' ? Number(nilai) : nilai;
        }
    }

    simpan();

    return entri;
}

function totalDari(entri) {
    let jumlah = 0;

    for (const n of entri.values()) {
        jumlah += n;
    }

    return jumlah;
}

function git(...argumen) {
    return execFileSync('git', argumen, { cwd: akar, encoding: 'utf8' });
}

if (!existsSync(resolve(akar, jalurBaseline))) {
    gagal(`Tidak menemukan ${jalurBaseline}; pemeriksaan ini tidak dapat membuktikan apa pun.`);
}

const sekarang = bacaEntri(readFileSync(resolve(akar, jalurBaseline), 'utf8'));

if (sekarang.size === 0) {
    // Baseline yang terbaca kosong hampir selalu berarti pembacanya yang rusak, bukan repo yang
    // bersih. Melaporkannya sebagai lulus akan menyembunyikan kerusakan itu selamanya.
    gagal(
        `Tidak satu pun entri terbaca dari ${jalurBaseline}.`,
        'Kalau baseline-nya memang sudah kosong, hapus berkasnya beserta pemeriksaan ini.',
        'Kalau tidak, pembaca entri di skrip ini yang rusak.',
    );
}

let sebelum;

try {
    sebelum = bacaEntri(git('show', `${pembanding}:${jalurBaseline}`));
} catch {
    gagal(
        `Tidak dapat membaca ${jalurBaseline} pada "${pembanding}".`,
        '',
        'Pemeriksaan ini membandingkan keadaan sekarang terhadap basisnya, jadi tanpa pembanding',
        'ia tidak dapat menyimpulkan apa pun — dan lulus tanpa membandingkan lebih berbahaya',
        'daripada tidak memeriksa sama sekali.',
        '',
        'Pada alur CI, langkah checkout harus mengambil riwayat penuh (`fetch-depth: 0`).',
        'Mengambilnya belakangan lewat `git fetch` tidak bisa: alur ini memakai',
        '`persist-credentials: false`, jadi tidak ada kredensial yang tertinggal untuk itu.',
    );
}

// Berkas yang dipindah membawa entrinya ke path dan nama kelas baru. Tanpa penerjemahan ini,
// pemindahan berkas yang isinya tidak berubah terbaca sebagai bungkaman baru di path baru —
// padahal itu bungkaman lama yang sama. Yang diterjemahkan hanya pasangan yang dikenali git
// sebagai rename, jadi entri yang benar-benar baru tetap tertangkap.
function terjemahkanPindahan(entri) {
    let daftar;

    try {
        daftar = git('diff', '--name-status', '-M', `${pembanding}...HEAD`, '--', 'apps/core/app');
    } catch {
        return entri;
    }

    const pindahan = [];

    for (const baris of daftar.split('\n')) {
        const [status, lama, baru] = baris.split('\t');

        if (!status?.startsWith('R') || !lama?.endsWith('.php') || !baru) {
            continue;
        }

        const path = (p) => p.replace(/^apps\/core\//, '');
        const kelas = (p) => 'App\\' + path(p).replace(/^app\//, '').replace(/\.php$/, '').replaceAll('/', '\\');

        pindahan.push({ dari: path(lama), ke: path(baru), kelasDari: kelas(lama), kelasKe: kelas(baru) });
    }

    if (pindahan.length === 0) {
        return entri;
    }

    // Nama kelas terpanjang lebih dulu, supaya `App\X\Y` tidak tertimpa penggantian `App\X`.
    pindahan.sort((a, b) => b.kelasDari.length - a.kelasDari.length);

    const hasil = new Map();

    for (const [kunci, jumlah] of entri) {
        let [path, identifier, ...pesan] = kunci.split('|');
        let teks = pesan.join('|');

        for (const p of pindahan) {
            if (path === p.dari) {
                path = p.ke;
            }

            // Di baseline, backslash nama kelas ditulis ganda karena pesannya pola regex.
            // Batas kata di belakangnya mencegah `App\Models\Party` ikut mengganti `App\Models\PartyType`.
            const pola = new RegExp(p.kelasDari.replaceAll('\\', '\\\\').replace(/[\\^$.*+?()[\]{}|]/g, '\\$&') + '(?!\\w)', 'g');
            teks = teks.replace(pola, () => p.kelasKe.replaceAll('\\', '\\\\'));
        }

        const baru = `${path}|${identifier}|${teks}`;
        hasil.set(baru, (hasil.get(baru) ?? 0) + jumlah);
    }

    return hasil;
}

sebelum = terjemahkanPindahan(sebelum);

const bungkamanBaru = [];

for (const [kunci, jumlah] of sekarang) {
    const dulu = sebelum.get(kunci) ?? 0;

    if (jumlah > dulu) {
        const [path, identifier] = kunci.split('|');
        const nama = identifier || 'tanpa identifier';

        bungkamanBaru.push(
            dulu === 0
                ? `  ${path}  (${nama})  entri baru, ${jumlah} error`
                : `  ${path}  (${nama})  ${dulu} → ${jumlah} error`,
        );
    }
}

const totalSekarang = totalDari(sekarang);
const totalSebelum = totalDari(sebelum);

if (bungkamanBaru.length > 0) {
    gagal(
        'Baseline PHPStan memuat bungkaman baru:',
        ...bungkamanBaru,
        '',
        `Total keseluruhan ${totalSebelum} → ${totalSekarang}, tetapi total bukan ukurannya:`,
        'membuang bungkaman lama tidak memberi hak menambah yang baru.',
        '',
        'Temuan baru diperbaiki pada kodenya, bukan ditambahkan ke daftar yang dibungkam — dan itu',
        'berlaku juga untuk menaikkan `count:` pada entri yang sudah ada. Kalau sebuah temuan',
        'benar-benar tidak dapat diperbaiki sekarang, itu keputusan yang pantas dibicarakan pada',
        'pull request-nya, bukan diselipkan ke berkas ini.',
    );
}

const dibuang = totalSebelum - totalSekarang;

console.log(
    dibuang > 0
        ? `Baseline PHPStan menyusut ${dibuang} error dibungkam (${totalSebelum} → ${totalSekarang}), tanpa satu pun bungkaman baru.`
        : `Baseline PHPStan tidak memuat bungkaman baru: tetap ${totalSekarang} error dibungkam.`,
);
