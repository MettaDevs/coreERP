<?php

namespace Modules\Apperp\ManagementAset\Services;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Apperp\ManagementAset\Models\master\BukuPenyusutan;
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\master\JenisAset;
use Modules\Apperp\ManagementAset\Models\master\KondisiAset;
use Modules\Apperp\ManagementAset\Models\master\LokasiAset;

/**
 * Berkas CSV saldo awal menjadi draf penerimaan saldo awal (feed posting finance, TODO 10.6).
 *
 * Tiga aturan yang menentukan bentuknya:
 *
 * 1. **Satu baris berkas satu baris penerimaan.** Baris yang tanggal perolehan, tanggal siap pakai,
 *    dan lokasinya sama menjadi satu draf, karena ketiganya milik kepala dokumen — satu dokumen tetap
 *    satu kedatangan, hanya saja kedatangannya di sistem lama.
 * 2. **Kode, bukan id.** Group, jenis, kondisi, lokasi, dan buku ditulis dengan kode yang tampil di
 *    layar master. Akumulasi buku selain buku yang di-post ditulis di kolom
 *    `akumulasi_per_unit:<KODE BUKU>` dan `periode_berjalan:<KODE BUKU>`; tanpa kolom itu buku
 *    tersebut memakai angka baris (K-28).
 * 3. **Angka mengikuti pemisah berkasnya.** Berkas bertitik koma dibaca seperti Excel berbahasa
 *    Indonesia menyimpannya — titik pemisah ribuan, koma pemisah desimal — dan berkas berkoma memakai
 *    titik desimal. Tanggal boleh `2022-01-15` atau `15/01/2022`.
 *
 * Isi baris tidak divalidasi di sini selain bentuknya. Draf yang tersusun melewati aturan yang sama
 * persis dengan layar penerimaan, dan kesalahannya dipetakan kembali ke nomor baris berkas.
 *
 * @phpstan-type HasilBaca array{
 *     rows: int,
 *     receipts: list<array{header: array{tanggal: string, tanggal_siap_pakai: ?string, lokasi_aset_id: ?string, lokasi: ?string}, details: list<array<string, mixed>>, lines: list<int>}>,
 *     rejected: list<array{line: int, field: ?string, reason: string}>,
 * }
 */
final class OpeningBalanceImport
{
    public const HEADER = [
        'tanggal', 'tanggal_siap_pakai', 'lokasi', 'nama', 'group', 'jenis', 'kondisi', 'jumlah',
        'nilai_per_unit', 'residu_per_unit', 'akumulasi_per_unit', 'periode_berjalan', 'keterangan',
    ];

    public const REQUIRED = ['tanggal', 'nama', 'group', 'jenis', 'jumlah', 'nilai_per_unit'];

    public const MAX_ROWS = 2000;

    /** Kolom angka yang boleh diisi per buku, ditulis `<kolom>:<KODE BUKU>`. */
    private const PER_BUKU = ['akumulasi_per_unit', 'periode_berjalan'];

    /** @return HasilBaca */
    public function read(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $pertama = strtok($contents, "\r\n");
        if ($pertama === false || trim($pertama) === '') {
            return self::tolakBerkas('Berkas kosong.');
        }
        $pemisah = substr_count($pertama, ';') > substr_count($pertama, ',') ? ';' : ',';

        $aliran = fopen('php://temp', 'r+');
        if ($aliran === false) {
            return self::tolakBerkas('Berkas tidak dapat dibaca.');
        }
        fwrite($aliran, $contents);
        rewind($aliran);

        $judul = fgetcsv($aliran, null, $pemisah, '"', '');
        [$kolom, $masalahJudul] = self::kolom(is_array($judul) ? $judul : []);
        if ($masalahJudul !== null) {
            fclose($aliran);

            return self::tolakBerkas($masalahJudul);
        }

        $baris = [];
        $nomor = 1;
        while (($isi = fgetcsv($aliran, null, $pemisah, '"', '')) !== false) {
            $nomor++;
            if ($isi === [null] || implode('', array_map(static fn ($sel): string => trim((string) $sel), $isi)) === '') {
                continue;
            }
            if (count($baris) >= self::MAX_ROWS) {
                fclose($aliran);

                return self::tolakBerkas(sprintf('Berkas paling banyak %d baris. Pecah berkasnya lalu impor bertahap.', self::MAX_ROWS));
            }
            $sel = [];
            foreach ($kolom as $urut => $nama) {
                $sel[$nama] = trim((string) ($isi[$urut] ?? ''));
            }
            $baris[] = ['line' => $nomor, 'cells' => $sel];
        }
        fclose($aliran);

        return $this->susun($baris, $kolom, $pemisah);
    }

    /**
     * @param  list<array{line: int, cells: array<string, string>}>  $baris
     * @param  list<string>  $kolom
     * @return HasilBaca
     */
    private function susun(array $baris, array $kolom, string $pemisah): array
    {
        $kodeBuku = [];
        foreach ($kolom as $nama) {
            if (str_contains($nama, ':')) {
                $kodeBuku[] = explode(':', $nama, 2)[1];
            }
        }
        $kodeBuku = array_values(array_unique($kodeBuku));
        $kode = [
            'group' => $this->ids(GroupAset::class, array_column(array_column($baris, 'cells'), 'group')),
            'jenis' => $this->ids(JenisAset::class, array_column(array_column($baris, 'cells'), 'jenis')),
            'kondisi' => $this->ids(KondisiAset::class, array_column(array_column($baris, 'cells'), 'kondisi')),
            'lokasi' => $this->ids(LokasiAset::class, array_column(array_column($baris, 'cells'), 'lokasi')),
            'buku' => $this->ids(BukuPenyusutan::class, $kodeBuku),
        ];

        $ditolak = [];
        $draf = [];
        foreach ($baris as ['line' => $nomor, 'cells' => $sel]) {
            $sebelum = count($ditolak);
            $salah = static function (string $field, string $alasan) use (&$ditolak, $nomor): void {
                $ditolak[] = ['line' => $nomor, 'field' => $field, 'reason' => $alasan];
            };
            foreach (self::REQUIRED as $wajib) {
                if (($sel[$wajib] ?? '') === '') {
                    $salah($wajib, 'Wajib diisi.');
                }
            }
            $tanggal = self::tanggal($sel['tanggal'] ?? '');
            if (($sel['tanggal'] ?? '') !== '' && $tanggal === null) {
                $salah('tanggal', 'Tanggal ditulis 2022-01-15 atau 15/01/2022.');
            }
            $siapPakai = ($sel['tanggal_siap_pakai'] ?? '') === '' ? null : self::tanggal($sel['tanggal_siap_pakai']);
            if (($sel['tanggal_siap_pakai'] ?? '') !== '' && $siapPakai === null) {
                $salah('tanggal_siap_pakai', 'Tanggal ditulis 2022-01-15 atau 15/01/2022.');
            }

            $id = [];
            foreach (['group', 'jenis', 'kondisi', 'lokasi'] as $jenis) {
                $nilai = $sel[$jenis] ?? '';
                $id[$jenis] = $nilai === '' ? null : ($kode[$jenis][mb_strtoupper($nilai)] ?? null);
                if ($nilai !== '' && $id[$jenis] === null) {
                    $salah($jenis, sprintf('Kode %s tidak ditemukan di master %s.', $nilai, $jenis));
                }
            }

            $angka = [];
            foreach (['jumlah', 'nilai_per_unit', 'residu_per_unit', 'akumulasi_per_unit', 'periode_berjalan'] as $nama) {
                $angka[$nama] = self::angka($sel[$nama] ?? '', $pemisah);
                if (($sel[$nama] ?? '') !== '' && $angka[$nama] === null) {
                    $salah($nama, sprintf('"%s" bukan angka.', $sel[$nama]));
                }
            }

            $perBuku = [];
            foreach ($kodeBuku as $bukuKode) {
                $akumulasi = self::angka($sel['akumulasi_per_unit:'.$bukuKode] ?? '', $pemisah);
                $periode = self::angka($sel['periode_berjalan:'.$bukuKode] ?? '', $pemisah);
                foreach (['akumulasi_per_unit' => $akumulasi, 'periode_berjalan' => $periode] as $nama => $nilai) {
                    if (($sel[$nama.':'.$bukuKode] ?? '') !== '' && $nilai === null) {
                        $salah($nama.':'.$bukuKode, sprintf('"%s" bukan angka.', $sel[$nama.':'.$bukuKode]));
                    }
                }
                if ($akumulasi === null && $periode === null) {
                    continue;
                }
                $bukuId = $kode['buku'][$bukuKode] ?? null;
                if ($bukuId === null) {
                    $salah('akumulasi_per_unit:'.$bukuKode, sprintf('Kode buku %s tidak ditemukan di master buku penyusutan.', $bukuKode));

                    continue;
                }
                // Kolom yang dikosongkan mengikuti angka baris, seperti buku yang tidak diisi sama sekali.
                $perBuku[] = [
                    'buku_id' => $bukuId,
                    'akumulasi_per_unit' => $akumulasi ?? $angka['akumulasi_per_unit'] ?? '0',
                    'periode_berjalan' => $periode ?? $angka['periode_berjalan'] ?? '0',
                ];
            }

            // Baris yang bentuknya sudah salah tidak ikut disusun: validasi draf hanya akan mengulang
            // kesalahan yang sama dengan pesan yang lebih kabur.
            if (count($ditolak) > $sebelum) {
                continue;
            }
            $kunci = implode('|', [$tanggal ?? '', $siapPakai ?? '', $id['lokasi'] ?? '']);
            $draf[$kunci] ??= [
                'header' => ['tanggal' => (string) $tanggal, 'tanggal_siap_pakai' => $siapPakai, 'lokasi_aset_id' => $id['lokasi'], 'lokasi' => ($sel['lokasi'] ?? '') === '' ? null : $sel['lokasi']],
                'details' => [],
                'lines' => [],
            ];
            $draf[$kunci]['details'][] = [
                'nama' => $sel['nama'] ?? '',
                'group_aset_id' => $id['group'],
                'jenis_aset_id' => $id['jenis'],
                'kondisi_aset_id' => $id['kondisi'],
                'jumlah' => $angka['jumlah'],
                'nilai_per_unit' => $angka['nilai_per_unit'],
                'residu_per_unit' => $angka['residu_per_unit'] ?? '0',
                'akumulasi_per_unit' => $angka['akumulasi_per_unit'] ?? '0',
                'periode_berjalan' => $angka['periode_berjalan'] ?? '0',
                'saldo_awal_buku' => $perBuku,
                'keterangan' => ($sel['keterangan'] ?? '') === '' ? null : $sel['keterangan'],
            ];
            $draf[$kunci]['lines'][] = $nomor;
        }

        return ['rows' => count($baris), 'receipts' => array_values($draf), 'rejected' => $ditolak];
    }

    /**
     * Nama kolom yang dikenali, dalam urutan berkas. Kolom yang tidak dikenal ditolak, supaya salah
     * ketik judul tidak diam-diam mengosongkan isinya.
     *
     * @param  list<string|null>  $judul
     * @return array{0: list<string>, 1: ?string}
     */
    private static function kolom(array $judul): array
    {
        $kolom = [];
        $asing = [];
        foreach ($judul as $nama) {
            $nama = trim((string) $nama);
            [$dasar, $buku] = str_contains($nama, ':') ? explode(':', $nama, 2) : [$nama, null];
            $dasar = mb_strtolower(trim($dasar));
            if ($buku !== null && in_array($dasar, self::PER_BUKU, true) && trim($buku) !== '') {
                $kolom[] = $dasar.':'.mb_strtoupper(trim($buku));
            } elseif ($buku === null && in_array($dasar, self::HEADER, true)) {
                $kolom[] = $dasar;
            } else {
                $kolom[] = '';
                $asing[] = $nama;
            }
        }
        if ($asing !== []) {
            return [$kolom, 'Kolom tidak dikenal: '.implode(', ', $asing).'. Unduh templatnya untuk melihat nama kolom yang benar.'];
        }
        $kurang = array_diff(self::REQUIRED, $kolom);
        if ($kurang !== []) {
            return [$kolom, 'Kolom wajib belum ada: '.implode(', ', $kurang).'.'];
        }

        return [$kolom, null];
    }

    /**
     * Id master per kode, huruf besar-kecil tidak dibedakan.
     *
     * @param  class-string<Model>  $model
     * @param  list<string|null>  $kode
     * @return array<string, string>
     */
    private function ids(string $model, array $kode): array
    {
        $kode = array_values(array_unique(array_filter(array_map(static fn ($nilai): string => mb_strtoupper(trim((string) $nilai)), $kode))));
        if ($kode === []) {
            return [];
        }

        $hasil = [];
        foreach ($model::query()->whereIn(DB::raw('upper(kode)'), $kode)->toBase()->get(['id', 'kode']) as $baris) {
            $hasil[mb_strtoupper((string) $baris->kode)] = (string) $baris->id;
        }

        return $hasil;
    }

    private static function tanggal(string $nilai): ?string
    {
        foreach (['!Y-m-d', '!d/m/Y'] as $format) {
            $tanggal = DateTimeImmutable::createFromFormat($format, $nilai);
            if ($tanggal !== false && $tanggal->format(ltrim($format, '!')) === $nilai) {
                return $tanggal->format('Y-m-d');
            }
        }

        return null;
    }

    /** Angka sebagai teks desimal bertitik, atau `null` bila kosong atau bukan angka. */
    private static function angka(string $nilai, string $pemisah): ?string
    {
        $nilai = str_replace([' ', "\u{00A0}"], '', $nilai);
        if ($nilai === '') {
            return null;
        }
        $nilai = $pemisah === ';' ? str_replace(',', '.', str_replace('.', '', $nilai)) : str_replace(',', '', $nilai);

        return preg_match('/^\d+(\.\d+)?$/', $nilai) === 1 ? $nilai : null;
    }

    /** @return HasilBaca */
    private static function tolakBerkas(string $alasan): array
    {
        return ['rows' => 0, 'receipts' => [], 'rejected' => [['line' => 1, 'field' => null, 'reason' => $alasan]]];
    }
}
