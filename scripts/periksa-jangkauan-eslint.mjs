// Membuktikan bahwa ESLint benar-benar melihat berkas di luar Core.
//
// Ini bukan pemeriksaan gaya. Ia menjaga satu jenis kegagalan yang tidak pernah berbunyi:
// sampai 10 September 2026 `eslint.config.js` berada di `apps/core/`, dan ESLint 9
// menetapkan base path dari letak berkas konfigurasinya. Akibatnya `eslint .` dari folder itu
// memeriksa **nol** berkas di bawah `modules/` — lalu keluar dengan kode 0.
//
// Bukan menolak, bukan memperingatkan; hanya diam. Puluhan berkas UI module karena itu tidak
// pernah diperiksa aturan hook React maupun urutan impor sejak module pertama mendarat, sementara
// alur CI melaporkan linter hijau di setiap pull request.
//
// Pelajarannya lebih umum daripada ESLint: sebuah pemeriksa yang tidak menemukan subjek tidak
// dapat dibedakan dari pemeriksa yang tidak menemukan pelanggaran. Skrip ini menutup jarak itu
// dengan menuntut subjeknya ada.

import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, readdirSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const akar = resolve(dirname(fileURLToPath(import.meta.url)), '..');

/** Folder di luar Core yang wajib ikut terperiksa, beserta alasan singkatnya. */
const wajibTerjangkau = [
    { awalan: 'modules/', alasan: 'halaman dan skenario uji beban milik module' },
    { awalan: 'packages/', alasan: 'paket antarmuka bersama' },
];

function gagal(...baris) {
    for (const b of baris) {
        console.error(b);
    }

    process.exit(1);
}

// Folder yang wajib terjangkau harus benar-benar berisi sesuatu. Kalau `modules/` kosong,
// pemeriksaan di bawah akan lulus tanpa membuktikan apa pun — dan itu keadaan yang harus
// diketahui, bukan dilewati.
for (const { awalan } of wajibTerjangkau) {
    const folder = join(akar, awalan);

    if (!existsSync(folder) || readdirSync(folder).length === 0) {
        gagal(
            `Folder ${awalan} tidak ada atau kosong.`,
            'Pemeriksaan jangkauan ESLint tidak dapat membuktikan apa pun tanpa subjek.',
        );
    }
}

// Laporannya ditulis ke berkas, bukan dibaca dari stdout. ESLint keluar bukan-nol ketika ada
// pelanggaran, dan penangkapan stdout dari proses yang gagal berbeda perilakunya antar sistem —
// `--output-file` membuat langkah ini tidak bergantung pada perbedaan itu.
const berkasLaporan = join(tmpdir(), `jangkauan-eslint-${process.pid}.json`);

try {
    execFileSync(
        process.execPath,
        [join(akar, 'node_modules', 'eslint', 'bin', 'eslint.js'), '.', '--format', 'json', '--output-file', berkasLaporan],
        { cwd: akar, encoding: 'utf8', stdio: 'ignore' },
    );
} catch {
    // Pelanggaran gaya bukan urusan skrip ini; yang diperiksa hanya jangkauannya.
}

let laporan;

try {
    laporan = JSON.parse(readFileSync(berkasLaporan, 'utf8'));
} catch {
    gagal('Laporan ESLint tidak dapat dibaca; jangkauannya tidak dapat dibuktikan.');
} finally {
    rmSync(berkasLaporan, { force: true });
}

const jalur = laporan.map((berkas) => berkas.filePath.split('\\').join('/'));
const kurang = [];

for (const { awalan, alasan } of wajibTerjangkau) {
    const jumlah = jalur.filter((p) => p.includes(`/${awalan}`)).length;

    if (jumlah === 0) {
        kurang.push(`  ${awalan} — ${alasan}`);
    } else {
        console.log(`ESLint memeriksa ${jumlah} berkas di bawah ${awalan}`);
    }
}

if (kurang.length > 0) {
    gagal(
        `ESLint memeriksa ${laporan.length} berkas, dan tidak satu pun berada di:`,
        ...kurang,
        '',
        'Penyebab yang paling mungkin: `eslint.config.js` berpindah dari akar repo, atau target',
        'perintahnya bukan lagi akar repo. ESLint 9 menetapkan base path dari letak berkas',
        'konfigurasi, dan berkas di luar base path itu dilewati tanpa satu pun peringatan.',
    );
}

console.log(`Jangkauan ESLint sah: ${laporan.length} berkas diperiksa dari akar repo.`);
