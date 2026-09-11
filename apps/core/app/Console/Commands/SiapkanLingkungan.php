<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Environment;
use App\Models\EnvironmentOperation;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Mengubah sebuah baris `environments` menjadi tempat kerja yang benar-benar ada isinya.
 *
 * Registry-nya sudah berdiri lebih dulu, dan sampai perintah ini ada ia hanya mencatat niat:
 * sebuah baris berstatus `provisioning` yang tidak menunjuk database mana pun. Yang dikerjakan di
 * sini persis pemisahnya — database dibuat, migration Core dijalankan ke dalamnya, lalu barisnya
 * naik ke `active`.
 *
 * ## Kenapa ia boleh dijalankan berkali-kali
 *
 * Rencananya ada di `docs/todo/environment-dan-pusat-admin/README.md`, dan satu kalimat di sana
 * mengikat seluruh bentuk kelas ini: **jangan menulis rollback kompensasi.** Menghapus database
 * yang setengah jadi melanggar larangan hapus fisik repo ini, dan ia balapan dengan percobaan
 * ulang — pengulangan yang menyala saat kompensasi sedang berjalan akan kehilangan apa yang baru
 * saja dibuatnya.
 *
 * Gantinya perbaikan maju. Tiap langkah dibuat sanggup melihat pekerjaannya sendiri:
 *
 * - Nama database dihitung dari baris yang sama, jadi percobaan kedua menghasilkan nama yang sama.
 * - "Database sudah ada" diperlakukan berhasil, bukan galat.
 * - Riwayat migration hidup di dalam database environment itu, jadi migration yang sudah
 *   teraplikasi dilewati sendiri oleh Laravel.
 *
 * Akibatnya penyiapan yang mati di tengah tidak meninggalkan apa pun yang harus dibereskan tangan;
 * ia meninggalkan environment `degraded` yang cukup dijalankan ulang dengan perintah yang sama.
 *
 * ## Kenapa gagal berarti `degraded` dan bukan sekadar pesan merah
 *
 * `active` satu-satunya status yang boleh dirutekan. Penyiapan yang gagal karena itu harus
 * menghasilkan tempat yang **tidak bisa dimasuki** — bukan tempat yang dimasuki lalu ternyata
 * setengah bermigrasi. Sebabnya ikut disimpan di `environment_operations`, karena cacat yang paling
 * sering berulang di repo ini adalah kegagalan yang tidak dapat dibaca siapa pun keesokan harinya.
 */
final class SiapkanLingkungan extends Command
{
    protected $signature = 'environment:siapkan {environment : Id baris environments yang disiapkan}';

    protected $description = 'Buatkan database sebuah environment, jalankan migration Core ke dalamnya, lalu aktifkan';

    /** Koneksi sementara ke database environment yang sedang disiapkan. */
    private const KONEKSI = 'lingkungan_disiapkan';

    /**
     * Koneksi kedua ke database pusat, dipakai hanya untuk `CREATE DATABASE`.
     *
     * PostgreSQL menolak `CREATE DATABASE` di dalam blok transaksi, dan koneksi bawaan sangat
     * mungkin sedang berada di dalam satu — di suite test ia selalu begitu. PDO terpisah adalah
     * satu-satunya cara berada di luarnya tanpa menyentuh transaksi milik orang lain. Ia hanya
     * pernah membaca `pg_database` dan membuat database baru; tidak satu pun tabel repo ini
     * disentuh lewat sini.
     */
    private const KONEKSI_PEMELIHARA = 'lingkungan_pemelihara';

    /**
     * Status yang boleh disiapkan.
     *
     * `active` sengaja di luar daftar. Menyiapkan ulang lingkungan yang sedang dipakai adalah cara
     * termurah menghapus data pelanggan tanpa sengaja, dan satu huruf salah ketik pada id sudah
     * cukup untuk sampai ke sana.
     */
    private const STATUS_BOLEH = ['provisioning', 'degraded'];

    /**
     * Berapa lama sebuah operasi boleh memegang kuncinya sebelum boleh direbut.
     *
     * Angkanya longgar dengan sengaja. Yang dijaga di sini bukan operasi yang lambat melainkan
     * operasi yang prosesnya sudah tidak ada — dan merebut milik proses yang sebenarnya masih
     * hidup jauh lebih mahal daripada menunggu. Migration Core penuh terukur di bawah satu menit;
     * tiga puluh memberi ruang untuk mesin yang jauh lebih lambat tanpa membuat operator menunggu
     * satu jam saat sesuatu benar-benar mati.
     *
     * Ini bukan heartbeat. Operasi yang berjalan lebih lama dari ini akan direbut meski sehat, dan
     * itu jawaban yang benar hanya selama tidak ada operasi yang memang wajar berjalan selama itu.
     * `environment:copy` kelak tidak masuk kategori itu — ia harus memperpanjang tenggatnya sendiri
     * selagi berjalan, dan itu pekerjaan yang lahir bersama perintahnya.
     */
    private const TENGGAT_MENIT = 30;

    public function handle(): int
    {
        $id = (string) $this->argument('environment');
        $lingkungan = Environment::query()->find($id);

        if (! $lingkungan instanceof Environment) {
            $this->error(sprintf('Environment "%s" tidak ada di registry.', $id));

            return self::FAILURE;
        }

        if (! in_array($lingkungan->status, self::STATUS_BOLEH, true)) {
            $this->error(sprintf(
                'Environment "%s" berstatus %s. Yang boleh disiapkan hanya %s — menyiapkan ulang '
                .'lingkungan yang sudah hidup akan menimpa isinya.',
                $lingkungan->slug,
                $lingkungan->status,
                implode(' atau ', self::STATUS_BOLEH),
            ));

            return self::FAILURE;
        }

        $operasi = $this->bukaOperasi($lingkungan);

        if (! $operasi instanceof EnvironmentOperation) {
            return self::FAILURE;
        }

        $langkah = 'nama-database';

        try {
            $nama = self::namaDatabase($lingkungan);

            $langkah = 'buat-database';
            $baru = $this->buatDatabase($nama);
            $this->line($baru
                ? sprintf('  database "%s" dibuat.', $nama)
                : sprintf('  database "%s" sudah ada; dilanjutkan.', $nama));

            $langkah = 'migration';
            $this->siapkanKoneksi($nama);
            $keluar = $this->callSilent('migrate', ['--database' => self::KONEKSI, '--force' => true]);

            if ($keluar !== self::SUCCESS) {
                throw new RuntimeException(sprintf('Perintah migrate berhenti dengan kode %d.', $keluar));
            }

            $langkah = 'sidik-skema';
            $sidik = $this->sidikSkema();

            $langkah = 'aktifkan';
            $lingkungan->update([
                'database_name' => $nama,
                'schema_migrated_at' => now(),
                'schema_fingerprint' => $sidik,
                'status' => 'active',
            ]);

            $operasi->update([
                'status' => 'succeeded',
                'step' => $langkah,
                'finished_at' => now(),
                'lease_until' => null,
                'detail' => ['database' => $nama, 'sidik' => $sidik],
            ]);

            $this->info(sprintf('Environment "%s" aktif di database "%s" (sidik %s).', $lingkungan->slug, $nama, $sidik));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->tandaiGagal($lingkungan, $operasi, $langkah, $e);

            $this->error(sprintf('Penyiapan berhenti di langkah "%s": %s', $langkah, $e->getMessage()));
            $this->line('Environment ditandai degraded. Perbaiki sebabnya lalu jalankan perintah yang sama sekali lagi.');

            return self::FAILURE;
        } finally {
            DB::purge(self::KONEKSI);
        }
    }

    /**
     * Nama database sebuah environment, dan ia selalu sama untuk baris yang sama.
     *
     * Dua hal bertabrakan di sini. Yang pertama batas keras: identifier PostgreSQL berhenti di 63
     * karakter dan kolom `database_name` juga 63, jadi bagian yang terbaca manusia harus dipotong.
     * Yang kedua akibatnya: dua tenant bernama panjang dengan awalan sama akan terpotong menjadi
     * nama yang persis sama, dan `database_name` punya unique index — tabrakannya baru muncul pada
     * pelanggan yang kebetulan sial, berbulan-bulan sesudahnya.
     *
     * Karena itu yang dipotong hanya bagian yang terbaca, dan keunikannya dititipkan pada potongan
     * sidik id barisnya yang **tidak pernah** ikut dipotong. Id, bukan slug: slug boleh dipakai
     * ulang setelah sebuah environment dihapus lunak, dan nama database yang terulang akan ditolak
     * unique index-nya justru pada environment pengganti yang sah.
     */
    public static function namaDatabase(Environment $lingkungan): string
    {
        $sidik = substr(hash('sha256', $lingkungan->id), 0, 10);
        $terbaca = self::bersihkan($lingkungan->tenant->slug).'_'.self::bersihkan($lingkungan->slug);

        // 63 dikurangi awalan `env_`, pemisah sebelum sidik, dan sidiknya sendiri.
        $terbaca = rtrim(substr($terbaca, 0, 63 - 4 - 1 - 10), '_');

        return 'env_'.$terbaca.'_'.$sidik;
    }

    /**
     * Membuka satu baris operasi, atau menolak karena sudah ada yang berjalan.
     *
     * Tidak ada penguncian di sini, dan itu disengaja. Partial unique index
     * `environment_operations_satu_berjalan` sudah menjadi kuncinya; menambah pemeriksaan
     * "apakah ada yang berjalan" di depannya hanya memindahkan balapan satu baris ke atas tanpa
     * menutupnya. Jadi barisnya disisipkan apa adanya, dan bentrokan yang muncul diterjemahkan.
     */
    private function bukaOperasi(Environment $lingkungan, bool $ambilAlih = true): ?EnvironmentOperation
    {
        $koneksi = DB::connection((new EnvironmentOperation)->getConnectionName());

        try {
            // Savepoint, dan itu bukan hiasan. PostgreSQL membatalkan **seluruh** blok transaksi
            // begitu satu perintah di dalamnya ditolak, jadi sisipan yang sejak awal memang boleh
            // ditolak akan menjatuhkan transaksi milik siapa pun yang kebetulan membungkus
            // perintah ini. Savepoint membuat penolakannya berhenti pada dirinya sendiri.
            return $koneksi->transaction(fn (): EnvironmentOperation => EnvironmentOperation::create([
                'environment_id' => $lingkungan->id,
                'operation' => 'provision',
                'status' => 'running',
                'step' => 'mulai',
                'started_at' => now(),
                'lease_until' => now()->addMinutes(self::TENGGAT_MENIT),
            ]));
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'environment_operations_satu_berjalan')) {
                throw $e;
            }

            if ($ambilAlih && $this->ambilAlihYangKedaluwarsa($lingkungan)) {
                return $this->bukaOperasi($lingkungan, ambilAlih: false);
            }

            $this->error(sprintf(
                'Sudah ada operasi yang berjalan atas environment "%s" dan tenggatnya belum lewat. '
                .'Tunggu sampai ia selesai, atau tunggu tenggatnya habis — percobaan berikutnya akan '
                .'mengambil alih sendiri.',
                $lingkungan->slug,
            ));

            return null;
        }
    }

    /**
     * Menyatakan gagal operasi yang tenggatnya sudah lewat, supaya percobaan ini boleh masuk.
     *
     * Ini pasangan dari kunci di atas, dan tanpanya kunci itu berubah menjadi kebuntuan. Proses yang
     * mati keras — OOM, container dibunuh, koneksi putus di tengah migration — tidak sempat menutup
     * barisnya, sehingga indeks "satu operasi berjalan" menolak percobaan ulang yang merupakan
     * satu-satunya pemulihan yang desain ini izinkan.
     *
     * Yang diambil alih **hanya** yang tenggatnya lewat. Operasi yang masih hidup tetap menang, dan
     * itulah yang membedakan pengambilalihan dari sekadar menabrak kunci orang.
     *
     * Ia menulis alasannya apa adanya, bukan menghapus barisnya. Riwayat yang kehilangan operasi
     * mati justru menghilangkan satu-satunya petunjuk kenapa sebuah environment tertinggal.
     */
    private function ambilAlihYangKedaluwarsa(Environment $lingkungan): bool
    {
        $kedaluwarsa = EnvironmentOperation::query()
            ->where('environment_id', $lingkungan->id)
            ->where('status', 'running')
            ->where('lease_until', '<', now())
            ->first();

        if (! $kedaluwarsa instanceof EnvironmentOperation) {
            return false;
        }

        $kedaluwarsa->update([
            'status' => 'failed',
            'finished_at' => now(),
            'lease_until' => null,
            'failure_message' => sprintf(
                'Tenggatnya habis pada %s tanpa pernah ditutup — prosesnya berhenti tanpa sempat '
                .'melaporkan apa pun. Operasi ini diambil alih percobaan berikutnya, dan langkah '
                .'terakhir yang sempat tercapai adalah "%s".',
                (string) $kedaluwarsa->getOriginal('lease_until'),
                $kedaluwarsa->step ?? 'tidak tercatat',
            ),
        ]);

        $this->warn(sprintf(
            'Operasi %s yang tenggatnya sudah lewat ditandai gagal dan diambil alih.',
            $kedaluwarsa->id,
        ));

        return true;
    }

    /**
     * Membuat database bila belum ada. Mengembalikan true bila ia benar-benar baru dibuat.
     *
     * Namanya ditempel langsung ke dalam SQL karena DDL memang tidak menerima parameter terikat.
     * Yang membuat itu aman bukan keyakinan melainkan bentuk namanya, yang diperiksa sebaris di
     * bawah: hanya huruf kecil, angka, dan garis bawah, sehingga tidak ada yang bisa dikutip keluar.
     */
    private function buatDatabase(string $nama): bool
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/', $nama) !== 1) {
            throw new RuntimeException(sprintf('Nama database "%s" tidak berbentuk identifier yang aman.', $nama));
        }

        $dasar = $this->konfigurasiDasar();

        // Penjaga terakhir sebelum sesuatu yang tidak bisa dibatalkan. Kalau perhitungan nama
        // pernah menghasilkan nama database pusat, langkah berikutnya akan menjalankan seluruh
        // migration ke atas data kerja orang.
        if ($nama === (string) ($dasar['database'] ?? '')) {
            throw new RuntimeException('Nama database environment sama dengan database pusat; penyiapan dihentikan.');
        }

        config(['database.connections.'.self::KONEKSI_PEMELIHARA => $dasar]);
        DB::purge(self::KONEKSI_PEMELIHARA);

        try {
            $koneksi = DB::connection(self::KONEKSI_PEMELIHARA);

            if ($koneksi->selectOne('select 1 from pg_database where datname = ?', [$nama]) !== null) {
                return false;
            }

            // `PDO::exec`, bukan `statement()` maupun `unprepared()`. Yang pertama menyiapkan
            // pernyataan lebih dulu, dan protokol extended query PostgreSQL membungkusnya dalam
            // transaksi implisit — persis yang dilarang untuk `CREATE DATABASE`. Yang kedua
            // menuntut literal-string, dan nama database di sini memang tidak pernah literal;
            // yang menjaganya adalah pemeriksaan bentuk identifier di awal method ini.
            $koneksi->getPdo()->exec(sprintf('CREATE DATABASE "%s"', $nama));

            return true;
        } catch (PDOException $e) {
            // Dua penyiapan yang berlomba. Yang kalah tetap boleh melanjutkan — database yang
            // dicarinya memang sudah ada sekarang.
            if (str_contains($e->getMessage(), 'already exists')) {
                return false;
            }

            throw $e;
        } finally {
            DB::purge(self::KONEKSI_PEMELIHARA);
        }
    }

    /**
     * Mendaftarkan koneksi ke database yang baru, lalu mendirikan schema yang ditunjuk search_path.
     *
     * Konfigurasinya disalin dari koneksi bawaan supaya host, kredensial, dan search_path tidak
     * pernah menyimpang darinya. `url` dikosongkan karena Laravel mendahulukannya di atas `database`
     * bila ia terisi — dan bila itu terjadi, seluruh migration di bawah ini akan berjalan ke
     * database pusat.
     */
    private function siapkanKoneksi(string $nama): void
    {
        $konfigurasi = $this->konfigurasiDasar();
        $konfigurasi['database'] = $nama;
        $konfigurasi['url'] = null;

        config(['database.connections.'.self::KONEKSI => $konfigurasi]);
        DB::purge(self::KONEKSI);

        foreach ($this->skemaDari($konfigurasi) as $skema) {
            DB::connection(self::KONEKSI)->statement(sprintf('CREATE SCHEMA IF NOT EXISTS "%s"', $skema));
        }
    }

    /**
     * Sidik skema: nama berkas migration terakhir yang teraplikasi di database itu.
     *
     * Bukan kolom versi yang harus dijaga seseorang. Justru karena versinya cuma satu, "tertinggal"
     * adalah fakta yang dihitung dengan membandingkan sidik environment terhadap sidik image.
     */
    private function sidikSkema(): string
    {
        $baris = DB::connection(self::KONEKSI)->table('migrations')->orderByDesc('id')->first();

        if (! is_object($baris) || ! property_exists($baris, 'migration')) {
            throw new RuntimeException('Migration berjalan tanpa meninggalkan satu baris pun riwayat.');
        }

        return (string) $baris->migration;
    }

    /**
     * Menutup operasi sebagai gagal dan menurunkan environment ke `degraded`.
     *
     * Constraint `environment_operations_gagal_beralasan` menolak baris gagal tanpa alasan, jadi
     * pesan kosong pun diganti nama kelas pengecualiannya — sebuah baris yang ditolak database di
     * sini akan menelan sebab kegagalan yang sebenarnya dan menggantinya dengan sebab palsu.
     */
    private function tandaiGagal(Environment $lingkungan, EnvironmentOperation $operasi, string $langkah, Throwable $e): void
    {
        $pesan = trim($e->getMessage());

        if ($pesan === '') {
            $pesan = $e::class;
        }

        $operasi->update([
            'status' => 'failed',
            'step' => $langkah,
            'failure_message' => mb_substr($pesan, 0, 2000),
            'finished_at' => now(),
            // Tenggatnya dilepas bersamaan. Ia hanya berarti selama operasinya berjalan, dan baris
            // selesai yang masih membawa tenggat terbaca seolah ia masih memegang sesuatu.
            'lease_until' => null,
        ]);

        $lingkungan->update(['status' => 'degraded']);
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

    /**
     * Schema yang harus ada di database baru supaya search_path koneksinya menunjuk sesuatu.
     *
     * `public` dilewati: ia sudah ada di tiap database baru, dan `CREATE SCHEMA` atasnya menuntut
     * hak yang belum tentu dimiliki peran aplikasi.
     *
     * @param  array<string, mixed>  $konfigurasi
     * @return list<string>
     */
    private function skemaDari(array $konfigurasi): array
    {
        $search = $konfigurasi['search_path'] ?? 'public';
        $daftar = is_array($search) ? $search : explode(',', (string) $search);

        $hasil = [];

        foreach ($daftar as $skema) {
            $bersih = trim((string) $skema, " \t\"'");

            if ($bersih === '' || $bersih === 'public' || preg_match('/^[a-z][a-z0-9_]*$/', $bersih) !== 1) {
                continue;
            }

            $hasil[] = $bersih;
        }

        return $hasil;
    }

    /** Mengubah sepotong slug menjadi bagian identifier yang sah, tanpa membuatnya unik. */
    private static function bersihkan(string $teks): string
    {
        $bersih = preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($teks)) ?? '';

        return trim($bersih, '_');
    }
}
