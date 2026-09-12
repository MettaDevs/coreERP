<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\MemegangOperasiLingkungan;
use App\Models\Environment;
use App\Models\EnvironmentOperation;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Membuang database lingkungan yang masa tenggangnya sudah habis. Satu-satunya yang benar-benar
 * menghapus di seluruh rancangan ini.
 *
 * Semua operasi lain dapat dibatalkan: penyiapan yang gagal dijalankan ulang, konversi meninggalkan
 * datanya utuh, hapus lunak punya pemulihannya. Yang di sini tidak. `DROP DATABASE` tidak punya
 * jalur mundur, dan tidak ada cadangan yang dijanjikan rancangan ini. Karena itu penjagaannya
 * paling ketat, dan dua di antaranya bahkan tidak hidup di berkas ini melainkan sebagai constraint
 * di PostgreSQL.
 *
 * ## Apa yang sebenarnya dibuang — dan apa yang tinggal
 *
 * Yang dibuang **databasenya**. Baris registrinya tinggal, dan itu bukan kelalaian:
 *
 * - `environment_operations.environment_id` menunjuk `environments` dengan `restrictOnDelete`.
 *   Komentar migrationnya menyebutkan alasannya terang-terangan — riwayat sebuah lingkungan yang
 *   dihapus tidak boleh ikut lenyap bersamanya, justru riwayat itu yang dibutuhkan untuk memahami
 *   kenapa ia dihapus. Jadi `DELETE` pada barisnya mustahil tanpa lebih dulu membuang persis yang
 *   paling dibutuhkan.
 * - Repo ini melarang penghapusan fisik baris, dan larangan itu punya alasannya sendiri di sini:
 *   nisannya yang menjawab "lingkungan apa yang dulu memakai database ini" ketika seseorang
 *   menemukan sisa berkas atau sebuah baris di cadangan.
 *
 * Jadi `restrictOnDelete` bukan halangan yang harus diakali melainkan **penentu bentuk perintah
 * ini**: tidak ada baris yang dihapus, tidak ada riwayat yang disentuh, dan `purged_at` yang
 * membedakan "dihapus lunak, isinya masih ada" dari "isinya sudah tidak ada di mana pun".
 *
 * ## Urutannya: buang dulu, catat kemudian
 *
 * Kebalikannya menggoda karena terasa lebih rapi, dan ia meninggalkan kerusakan yang tidak dapat
 * ditemukan siapa pun. Baris yang ditandai dibuang lebih dulu lalu dropnya gagal akan dilewati
 * setiap sapuan berikutnya — database yatim yang memakan disk selamanya, dan tidak satu pun baris
 * di registry yang menunjuknya.
 *
 * Urutan yang dipakai gagal ke arah yang dapat diperbaiki: database sudah hilang tetapi barisnya
 * belum ditandai berarti sapuan berikutnya mencobanya lagi, menemukan `IF EXISTS` tidak menemukan
 * apa-apa, lalu menyelesaikan pencatatannya. Perbaikan maju, sama seperti seluruh rancangan ini.
 *
 * ## Dua cara memanggilnya, satu tempat penjagaannya
 *
 * Tanpa argumen ia menyapu semua yang layak dibuang — itu yang dijalankan penjadwal. Dengan id ia
 * membuang satu, dan menolaknya dengan kalimat yang terbaca bila tidak layak.
 *
 * Yang mengikat: keduanya memanggil `alasanMenolak()` yang sama. Jalur bernama yang punya
 * pemeriksaannya sendiri adalah jalur yang kelak lebih longgar daripada sapuannya — dan yang lebih
 * longgar di sini berarti sebuah `DROP DATABASE` yang seharusnya tidak pernah terjadi.
 */
final class HapusLingkunganPermanen extends Command
{
    use MemegangOperasiLingkungan;

    protected $signature = 'environment:hapus-permanen
        {environment? : Id satu baris environments; tanpa ini seluruh yang layak dibuang disapu}';

    protected $description = 'Buang database lingkungan yang masa tenggangnya sudah habis, beserta isinya';

    /** Koneksi kedua ke database pusat, dipakai hanya untuk `DROP DATABASE`. */
    private const KONEKSI_PEMELIHARA = 'lingkungan_pembuang';

    /**
     * Tenggat kuncinya, dan ia yang paling panjang di antara operasi lingkungan.
     *
     * `DROP DATABASE` sendiri cepat — ia sebagian besar membuang berkas — tetapi ia menunggu sesi
     * yang masih menempel, dan `WITH (FORCE)` memutus sesi itu satu per satu. Lima belas menit
     * memberi ruang untuk database besar di disk yang sibuk tanpa membuat sebuah proses yang
     * benar-benar mati menahan lingkungannya sampai sapuan besok.
     */
    protected function tenggatOperasiMenit(): int
    {
        return 15;
    }

    public function handle(): int
    {
        $id = $this->argument('environment');

        if (is_string($id) && $id !== '') {
            return $this->buangYangDisebut($id);
        }

        return $this->sapuSemua();
    }

    /**
     * Apakah penjadwal perlu menjalankan perintah ini sama sekali.
     *
     * `Schedule::environments()` membaca `APP_ENV` dan karena itu tidak berguna di sini: yang
     * menentukan bukan nama lingkungan proses melainkan isi registry. Pemasangan on-prem tidak
     * punya sisi pusat sama sekali, dan setiap lingkungannya `production` yang tidak pernah
     * dihapus lunak — jadi perintah yang paling merusak di repo ini tidak perlu bangun setiap
     * malam di sana.
     *
     * Kegagalan membaca registry dijawab **melewatkan**, bukan menjalankan. Penjadwal yang tidak
     * dapat membaca registry adalah penjadwal yang tidak tahu apa yang akan dibuangnya, dan satu
     * malam yang terlewat jauh lebih murah daripada satu `DROP DATABASE` yang diputuskan tanpa
     * dasar. Ia juga menjaga `schedule:run` tetap berjalan: pengecualian yang lolos dari sebuah
     * filter menjatuhkan perintah terjadwal lain yang mengantre sesudahnya.
     */
    public static function tidakAdaYangPerluDibuang(): bool
    {
        try {
            return Environment::query()
                ->whereNotNull('deleted_at')
                ->whereNull('purged_at')
                ->doesntExist();
        } catch (Throwable $e) {
            Log::warning('Registry lingkungan tidak terbaca; pembuangan permanen dilewatkan malam ini.', [
                'sebab' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /** Membuang satu lingkungan yang disebut operator, beserta penolakan yang terbaca. */
    private function buangYangDisebut(string $id): int
    {
        $lingkungan = Environment::query()->find($id);

        if (! $lingkungan instanceof Environment) {
            $this->error(sprintf('Environment "%s" tidak ada di registry.', $id));

            return self::FAILURE;
        }

        return $this->buangSatu($lingkungan) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Menyapu setiap lingkungan yang masa tenggangnya sudah habis.
     *
     * Sama seperti sapuan kedaluwarsa, ia melanjutkan sesudah gagal dan mencatat tiap hasilnya.
     * Satu database yang menolak dibuang — sesi yang tidak dapat diputus, hak yang kurang — tidak
     * boleh membuat sisa antreannya menumpuk diam-diam sampai disknya penuh.
     */
    private function sapuSemua(): int
    {
        $daftar = Environment::query()
            ->where('kind', '<>', 'production')
            ->where('status', 'soft_deleted')
            ->whereNotNull('deleted_at')
            ->whereNull('purged_at')
            ->where('purge_after', '<=', now())
            ->orderBy('purge_after')
            ->get();

        if ($daftar->isEmpty()) {
            $this->info('Tidak ada lingkungan yang masa tenggangnya sudah habis.');

            return self::SUCCESS;
        }

        $this->line(sprintf('%d lingkungan siap dibuang permanen.', $daftar->count()));

        $berhasil = 0;
        $gagal = 0;

        foreach ($daftar as $lingkungan) {
            if ($this->buangSatu($lingkungan)) {
                $berhasil++;

                continue;
            }

            $gagal++;
        }

        $this->info(sprintf('Pembuangan selesai: %d dibuang, %d gagal.', $berhasil, $gagal));

        if ($gagal > 0) {
            $this->error(sprintf(
                '%d lingkungan tidak jadi dibuang. Sebabnya ada di `environment_operations` dan di '
                .'log aplikasi; databasenya masih memakan disk sampai sebabnya dibereskan.',
                $gagal,
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** Membuang satu lingkungan, dan menelan kegagalannya supaya sisa antreannya tetap jalan. */
    private function buangSatu(Environment $lingkungan): bool
    {
        $sebab = $this->alasanMenolak($lingkungan);

        if ($sebab !== null) {
            $this->error(sprintf('  "%s" tidak dibuang: %s', $lingkungan->slug, $sebab));

            return false;
        }

        $operasi = $this->bukaOperasi($lingkungan, 'purge');

        if (! $operasi instanceof EnvironmentOperation) {
            Log::warning('Pembuangan permanen dilewati karena operasi lain sedang berjalan.', [
                'environment' => $lingkungan->id,
                'slug' => $lingkungan->slug,
            ]);

            return false;
        }

        $langkah = 'periksa-ulang';

        try {
            // Diperiksa ulang dari barisnya yang baru dibaca, sesudah kuncinya dipegang.
            //
            // Pemeriksaan di atas berjalan sebelum kunci ada, dan di antara keduanya sebuah
            // `environment:pulihkan` boleh saja menang — memulihkan lingkungan yang sedetik
            // kemudian databasenya dibuang perintah ini. Jendelanya sempit dan akibatnya total,
            // jadi ia ditutup di sini: sesudah titik ini pemulihan tidak dapat masuk, karena
            // `environment_operations_satu_berjalan` hanya mengizinkan satu operasi per lingkungan.
            $lingkungan->refresh();
            $sebab = $this->alasanMenolak($lingkungan);

            if ($sebab !== null) {
                throw new RuntimeException('Keadaannya berubah sesudah kunci dipegang: '.$sebab);
            }

            $nama = $lingkungan->database_name;
            $dihapusLunak = self::waktu($lingkungan->deleted_at);
            $bolehDibuang = self::waktu($lingkungan->purge_after);

            $langkah = 'buang-database';
            $dibuang = $this->buangDatabase($nama);

            $this->line($dibuang
                ? sprintf('  "%s": database "%s" dibuang.', $lingkungan->slug, (string) $nama)
                : sprintf('  "%s": tidak punya database sendiri; hanya barisnya yang ditandai.', $lingkungan->slug));

            $langkah = 'tandai-dibuang';
            $this->tandaiDibuang($lingkungan);

            $operasi->update([
                'status' => 'succeeded',
                'step' => $langkah,
                'finished_at' => now(),
                'lease_until' => null,
                'detail' => [
                    'database' => $nama,
                    'database_dibuang' => $dibuang,
                    'dihapus_lunak_pada' => $dihapusLunak?->toIso8601String(),
                    'boleh_dibuang_pada' => $bolehDibuang?->toIso8601String(),
                ],
            ]);

            return true;
        } catch (Throwable $e) {
            $this->tutupOperasiSebagaiGagal($operasi, $langkah, $e);

            Log::error('Pembuangan permanen gagal pada satu lingkungan.', [
                'environment' => $lingkungan->id,
                'slug' => $lingkungan->slug,
                'langkah' => $langkah,
                'sebab' => $e->getMessage(),
            ]);

            $this->error(sprintf('  "%s" gagal dibuang di langkah "%s": %s', $lingkungan->slug, $langkah, $e->getMessage()));

            return false;
        }
    }

    /**
     * Kenapa sebuah lingkungan tidak boleh dibuang — atau null bila ia memang layak.
     *
     * Satu tempat, dipakai jalur bernama maupun sapuan. Urutannya dipilih supaya kalimat yang
     * keluar adalah kalimat yang paling menjelaskan: yang sudah dibuang disebut sudah dibuang,
     * bukan disebut "masa tenggangnya habis" — padahal keduanya benar.
     */
    private function alasanMenolak(Environment $lingkungan): ?string
    {
        // Produksi, apa pun statusnya. Ia tempat kerja pelanggan yang sedang membayar, dan tidak
        // ada keadaan di rancangan ini yang membuat membuangnya lewat perintah terjadwal menjadi
        // jawaban yang benar. Constraint `environments_produksi_tidak_dibuang` menolaknya sekali
        // lagi di PostgreSQL; yang di sini ada supaya penolakannya berupa kalimat, bukan galat SQL.
        if ($lingkungan->produksi()) {
            return 'ia berjenis produksi. Produksi tidak pernah dibuang permanen lewat perintah ini, '
                .'apa pun statusnya — yang harus diputuskan lebih dulu adalah nasib datanya, dan itu '
                .'keputusan orang.';
        }

        if ($lingkungan->status !== 'soft_deleted' || $lingkungan->deleted_at === null) {
            return sprintf(
                'ia belum dihapus lunak (statusnya %s). Masa tenggang adalah satu-satunya jendela '
                .'tempat sebuah kesalahan masih dapat dibatalkan, dan melewatinya berarti membuang '
                .'lingkungan yang mungkin masih dipakai.',
                $lingkungan->status,
            );
        }

        if ($this->sudahDibuang($lingkungan)) {
            return 'isinya sudah dibuang sebelumnya; yang tersisa hanya barisnya beserta riwayatnya.';
        }

        $bolehDibuang = self::waktu($lingkungan->purge_after);

        if ($bolehDibuang === null) {
            return 'ia tidak punya tanggal boleh-dibuang. Itu seharusnya mustahil — '
                .'`environments_hapus_berpasangan` mengikat keduanya — jadi barisnya perlu diperiksa '
                .'tangan sebelum apa pun dibuang.';
        }

        if ($bolehDibuang->isFuture()) {
            return sprintf(
                'masa tenggangnya belum habis; isinya masih dapat dipulihkan sampai %s.',
                $bolehDibuang->toDateTimeString(),
            );
        }

        return null;
    }

    /**
     * Apakah isinya sudah dibuang sebelumnya.
     *
     * Lewat query, bukan lewat properti modelnya: `purged_at` lahir bersama perintah ini dan
     * modelnya sedang dipegang pekerjaan lain.
     */
    private function sudahDibuang(Environment $lingkungan): bool
    {
        return Environment::query()
            ->whereKey($lingkungan->id)
            ->whereNotNull('purged_at')
            ->exists();
    }

    /**
     * Membuang databasenya. Mengembalikan false bila lingkungan itu memang tidak punya database
     * sendiri.
     *
     * `database_name` yang kosong berarti "ikut database koneksi bawaan" — keadaan pooled, dan
     * keadaan lingkungan yang tidak pernah selesai disiapkan. Tidak ada yang boleh dibuang di sana:
     * database bawaan memuat data seluruh tenant lain. Yang kosong karena itu **dilewati, bukan
     * ditebak**, dan barisnya tetap ditandai — untuk yang tidak pernah disiapkan itu memang benar,
     * datanya tidak pernah ada di mana pun.
     *
     * Tiga penjaga berdiri sebelum satu perintah pun dikirim, dan ketiganya menjaga kesalahan yang
     * sama: membuang database yang bukan miliknya.
     */
    private function buangDatabase(?string $nama): bool
    {
        if ($nama === null || $nama === '') {
            return false;
        }

        // Nama ditempel langsung ke DDL karena DDL memang tidak menerima parameter terikat. Yang
        // membuatnya aman bukan keyakinan melainkan bentuknya, yang diperiksa di sini: hanya huruf
        // kecil, angka, dan garis bawah, sehingga tidak ada yang dapat dikutip keluar.
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/', $nama) !== 1) {
            throw new RuntimeException(sprintf(
                'Nama database "%s" tidak berbentuk identifier yang aman; pembuangan dihentikan.',
                $nama,
            ));
        }

        $dasar = $this->konfigurasiDasar();
        $terlarang = array_filter([
            (string) ($dasar['database'] ?? ''),
            (string) config('coreerp.database'),
        ], static fn (string $n): bool => $n !== '');

        // Penjaga terakhir sebelum sesuatu yang tidak dapat dibatalkan. Sebuah baris registry yang
        // `database_name`-nya salah isi — disunting tangan, atau ditulis jalur yang keliru — akan
        // membuat perintah ini membuang database pusat beserta seluruh pelanggan di dalamnya.
        if (in_array($nama, $terlarang, true)) {
            throw new RuntimeException(sprintf(
                'Database "%s" adalah database pusat, bukan milik sebuah lingkungan; pembuangan dihentikan.',
                $nama,
            ));
        }

        config(['database.connections.'.self::KONEKSI_PEMELIHARA => $dasar]);
        DB::purge(self::KONEKSI_PEMELIHARA);

        try {
            // `PDO::exec`, bukan `statement()`. Yang terakhir menyiapkan pernyataannya lebih dulu,
            // dan protokol extended query PostgreSQL membungkusnya dalam transaksi implisit —
            // persis yang dilarang untuk `DROP DATABASE`.
            //
            // `WITH (FORCE)` memutus sesi yang masih menempel. Tanpanya satu koneksi yang lupa
            // ditutup — milik worker, milik proses yang mati setengah — sudah cukup untuk membuat
            // pembuangan gagal setiap malam dengan "is being accessed by other users", dan
            // databasenya tidak pernah hilang.
            //
            // `IF EXISTS` yang membuatnya aman diulang: percobaan yang mati sesudah drop tetapi
            // sebelum pencatatannya akan menemukan database itu memang sudah tidak ada, lalu
            // menyelesaikan pencatatannya.
            DB::connection(self::KONEKSI_PEMELIHARA)
                ->getPdo()
                ->exec(sprintf('DROP DATABASE IF EXISTS "%s" WITH (FORCE)', $nama));

            return true;
        } finally {
            DB::purge(self::KONEKSI_PEMELIHARA);
        }
    }

    /**
     * Menandai barisnya sebagai sudah dibuang isinya.
     *
     * `database_name` sengaja **tidak** dikosongkan. Pada tabel ini kosong sudah punya arti yang
     * terpasang sejak awal — "ikut database koneksi bawaan" — sehingga mengosongkannya mengubah
     * nisan menjadi baris yang terbaca menunjuk database pusat. Nama yang tinggal juga yang
     * menjawab "database mana yang dulu dipakai" ketika seseorang menemukan sisa berkas atau satu
     * baris di cadangan.
     *
     * Dibungkus transaksi bersarang, dan syaratnya diulang pada penulisannya: satu-satunya jalur
     * yang dapat mengubah keadaannya di sela ini sudah dihalangi kunci operasi, dan compare-and-set
     * ini lapis terakhirnya.
     */
    private function tandaiDibuang(Environment $lingkungan): void
    {
        $koneksi = DB::connection($lingkungan->getConnectionName());

        $terkena = (int) $koneksi->transaction(fn (): int => Environment::query()
            ->whereKey($lingkungan->id)
            ->whereNotNull('deleted_at')
            ->whereNull('purged_at')
            ->update(['purged_at' => now()]));

        if ($terkena !== 1) {
            throw new RuntimeException(
                'Databasenya sudah dibuang, tetapi barisnya tidak dapat ditandai — keadaannya '
                .'berubah di sela keduanya. Jalankan perintah yang sama lagi: dropnya aman diulang, '
                .'dan yang tersisa hanya pencatatannya.'
            );
        }

        $lingkungan->refresh();
    }

    /**
     * Membaca sebuah kolom waktu milik `Environment` sebagai waktu, apa pun yang dilihat analisa.
     *
     * Alasannya sama dengan yang tertulis di `SapuLingkunganKedaluwarsa`: cast `datetime` milik
     * model itu dideklarasikan lewat method `casts()`, dan analisa statis tetap melihatnya sebagai
     * teks. Yang datang saat berjalan selalu `Carbon`.
     */
    private static function waktu(mixed $nilai): ?CarbonInterface
    {
        if ($nilai instanceof CarbonInterface) {
            return $nilai;
        }

        return is_string($nilai) && $nilai !== '' ? Carbon::parse($nilai) : null;
    }

    /** @return array<string, mixed> */
    private function konfigurasiDasar(): array
    {
        $bawaan = (string) config('database.default');
        $konfigurasi = config('database.connections.'.$bawaan);

        if (! is_array($konfigurasi)) {
            throw new RuntimeException(sprintf('Koneksi bawaan "%s" tidak terbaca dari config.', $bawaan));
        }

        /** @var array<string, mixed> $konfigurasi */
        return $konfigurasi;
    }
}
