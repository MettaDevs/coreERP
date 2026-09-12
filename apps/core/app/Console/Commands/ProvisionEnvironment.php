<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Modules\InstallEntitledModules;
use App\Console\Commands\Concerns\HoldsEnvironmentOperation;
use App\Models\Environment;
use App\Models\EnvironmentOperation;
use App\Support\ControlPlane\EnvironmentConnection;
use Illuminate\Console\Command;
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
final class ProvisionEnvironment extends Command
{
    use HoldsEnvironmentOperation;

    protected $signature = 'environment:provision {environment : Id baris environments yang disiapkan}'
        .' {--requested-by= : Id user yang meminta; dipakai konsol operator supaya riwayatnya bernama}';

    protected $description = 'Buatkan database sebuah environment, jalankan migration Core ke dalamnya, lalu aktifkan';

    /** Koneksi sementara ke database environment yang sedang disiapkan. */
    private const CONNECTION = 'environment_provisioning';

    /**
     * Status yang boleh disiapkan.
     *
     * `active` sengaja di luar daftar. Menyiapkan ulang lingkungan yang sedang dipakai adalah cara
     * termurah menghapus data pelanggan tanpa sengaja, dan satu huruf salah ketik pada id sudah
     * cukup untuk sampai ke sana.
     */
    private const ALLOWED_STATUSES = ['provisioning', 'degraded'];

    public function __construct(
        private readonly InstallEntitledModules $modules,
        private readonly EnvironmentConnection $connections,
    ) {
        parent::__construct();
    }

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
    protected function operationLeaseMinutes(): int
    {
        return 30;
    }

    /**
     * Dua bentuk diterima, dan itu bukan kelonggaran melainkan perbaikan cacat.
     *
     * Versi pertama hanya menerima string, karena begitulah bentuknya ketika opsi ini diketik di
     * terminal. Tetapi pemanggil yang sebenarnya `Artisan::call()` dari controller internal, dan ia
     * meneruskan **int** apa adanya lewat `ArrayInput`. Akibatnya penyiapan dari tombol tetap
     * berhasil sepenuhnya sementara kolom "Oleh" pada riwayat berbunyi "Sistem" — kegagalan yang
     * tidak berbunyi di mana pun.
     *
     * Testnya ikut setuju dengan asumsi yang salah itu, karena ia memanggil perintahnya dengan
     * `(string) $id`. Yang menemukannya satu panggilan HTTP sungguhan.
     */
    protected function requestedBy(): ?int
    {
        // `$this->input->getOption()`, bukan `$this->option()`. Keduanya membaca nilai yang sama,
        // tetapi PHPDoc Laravel menyatakan yang kedua mengembalikan `string|array|bool|null` —
        // padahal `ArrayInput` menyimpan apa pun yang diberikan pemanggilnya apa adanya. Memakai
        // yang pertama membuat pemeriksaan `int` di bawah menjadi pemeriksaan yang jujur, bukan
        // cabang yang menurut analisa statis tidak pernah tercapai padahal ia justru satu-satunya
        // yang tercapai di jalur sungguhan.
        $id = $this->input->getOption('requested-by');

        if (is_int($id)) {
            return $id;
        }

        return is_string($id) && $id !== '' && ctype_digit($id) ? (int) $id : null;
    }

    public function handle(): int
    {
        $id = (string) $this->argument('environment');
        $environment = Environment::query()->find($id);

        if (! $environment instanceof Environment) {
            $this->error(sprintf('Environment "%s" tidak ada di registry.', $id));

            return self::FAILURE;
        }

        if (! in_array($environment->status, self::ALLOWED_STATUSES, true)) {
            $this->error(sprintf(
                'Environment "%s" berstatus %s. Yang boleh disiapkan hanya %s — menyiapkan ulang '
                .'lingkungan yang sudah hidup akan menimpa isinya.',
                $environment->slug,
                $environment->status,
                implode(' atau ', self::ALLOWED_STATUSES),
            ));

            return self::FAILURE;
        }

        $operation = $this->openOperation($environment, 'provision');

        if (! $operation instanceof EnvironmentOperation) {
            return self::FAILURE;
        }

        $step = 'nama-database';

        try {
            $name = self::databaseName($environment);

            $step = 'buat-database';
            $created = $this->createDatabase($name);
            $this->line($created
                ? sprintf('  database "%s" dibuat.', $name)
                : sprintf('  database "%s" sudah ada; dilanjutkan.', $name));

            $step = 'migration';
            $this->prepareConnection($name);
            $exitCode = $this->callSilent('migrate', ['--database' => self::CONNECTION, '--force' => true]);

            if ($exitCode !== self::SUCCESS) {
                throw new RuntimeException(sprintf('Perintah migrate berhenti dengan kode %d.', $exitCode));
            }

            $step = 'sidik-skema';
            $fingerprint = $this->schemaFingerprint();

            /*
             * Databasenya dicatat **sebelum** module dipasang, dan statusnya belum dinaikkan.
             *
             * Urutan ini yang membuat langkah berikutnya mungkin sama sekali: pemasangan module
             * menanyakan `database_name` untuk tahu ke mana ia harus menulis, dan baris yang belum
             * mencatatnya akan mengirim seluruh migration module ke database pusat. Statusnya tetap
             * `provisioning` sampai isinya lengkap — lingkungan yang setengah terisi harus menjadi
             * tempat yang tidak dapat dimasuki, bukan tempat yang dimasuki lalu ternyata separuh.
             */
            $step = 'catat-database';
            $environment->update([
                'database_name' => $name,
                'schema_migrated_at' => now(),
                'schema_fingerprint' => $fingerprint,
            ]);

            $step = 'pasang-module';
            $modules = $this->modules->into($environment);
            $this->line($modules === []
                ? '  tidak ada module yang dibeli tenant ini; hanya skema Core yang dipasang.'
                : sprintf('  module terpasang: %s.', implode(', ', $modules)));

            $step = 'aktifkan';
            $environment->update(['status' => 'active']);

            $operation->update([
                'status' => 'succeeded',
                'step' => $step,
                'finished_at' => now(),
                'lease_until' => null,
                'detail' => ['database' => $name, 'sidik' => $fingerprint, 'module' => $modules],
            ]);

            $this->info(sprintf('Environment "%s" aktif di database "%s" (sidik %s).', $environment->slug, $name, $fingerprint));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->markFailed($environment, $operation, $step, $e);

            $this->error(sprintf('Penyiapan berhenti di langkah "%s": %s', $step, $e->getMessage()));
            $this->line('Environment ditandai degraded. Perbaiki sebabnya lalu jalankan perintah yang sama sekali lagi.');

            return self::FAILURE;
        } finally {
            DB::purge(self::CONNECTION);
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
    public static function databaseName(Environment $environment): string
    {
        $fingerprint = substr(hash('sha256', $environment->id), 0, 10);
        $readable = self::sanitize($environment->tenant->slug).'_'.self::sanitize($environment->slug);

        // 63 dikurangi awalan `env_`, pemisah sebelum sidik, dan sidiknya sendiri.
        $readable = rtrim(substr($readable, 0, 63 - 4 - 1 - 10), '_');

        return 'env_'.$readable.'_'.$fingerprint;
    }

    /**
     * Membuat database bila belum ada. Mengembalikan true bila ia benar-benar baru dibuat.
     *
     * Namanya ditempel langsung ke dalam SQL karena DDL memang tidak menerima parameter terikat.
     * Yang membuat itu aman bukan keyakinan melainkan bentuk namanya, yang diperiksa sebaris di
     * bawah: hanya huruf kecil, angka, dan garis bawah, sehingga tidak ada yang bisa dikutip keluar.
     */
    private function createDatabase(string $name): bool
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/', $name) !== 1) {
            throw new RuntimeException(sprintf('Nama database "%s" tidak berbentuk identifier yang aman.', $name));
        }

        $base = $this->connections->baseConfig();

        // Penjaga terakhir sebelum sesuatu yang tidak bisa dibatalkan. Kalau perhitungan nama
        // pernah menghasilkan nama database pusat, langkah berikutnya akan menjalankan seluruh
        // migration ke atas data kerja orang.
        if ($name === (string) ($base['database'] ?? '')) {
            throw new RuntimeException('Nama database environment sama dengan database pusat; penyiapan dihentikan.');
        }

        $maintenance = $this->connections->maintenance();

        try {
            $koneksi = DB::connection($maintenance);

            if ($koneksi->selectOne('select 1 from pg_database where datname = ?', [$name]) !== null) {
                return false;
            }

            // `PDO::exec`, bukan `statement()` maupun `unprepared()`. Yang pertama menyiapkan
            // pernyataan lebih dulu, dan protokol extended query PostgreSQL membungkusnya dalam
            // transaksi implisit — persis yang dilarang untuk `CREATE DATABASE`. Yang kedua
            // menuntut literal-string, dan nama database di sini memang tidak pernah literal;
            // yang menjaganya adalah pemeriksaan bentuk identifier di awal method ini.
            $koneksi->getPdo()->exec(sprintf('CREATE DATABASE "%s"', $name));

            return true;
        } catch (PDOException $e) {
            // Dua penyiapan yang berlomba. Yang kalah tetap boleh melanjutkan — database yang
            // dicarinya memang sudah ada sekarang.
            if (str_contains($e->getMessage(), 'already exists')) {
                return false;
            }

            throw $e;
        } finally {
            DB::purge($maintenance);
        }
    }

    /**
     * Mendaftarkan koneksi ke database yang baru, lalu mendirikan schema yang ditunjuk search_path.
     *
     * Isinya pindah ke {@see EnvironmentConnection} ketika pemasangan module ikut membutuhkannya.
     * Salinan ketiga dari logika yang sama adalah salinan yang akan menyimpang, dan yang menyimpang
     * di sini adalah jawaban atas pertanyaan "database mana".
     */
    private function prepareConnection(string $name): void
    {
        $this->connections->register(self::CONNECTION, $name);
        $this->connections->createSchemas(self::CONNECTION);
    }

    /**
     * Sidik skema: nama berkas migration terakhir yang teraplikasi di database itu.
     *
     * Bukan kolom versi yang harus dijaga seseorang. Justru karena versinya cuma satu, "tertinggal"
     * adalah fakta yang dihitung dengan membandingkan sidik environment terhadap sidik image.
     */
    private function schemaFingerprint(): string
    {
        $row = DB::connection(self::CONNECTION)->table('migrations')->orderByDesc('id')->first();

        if (! is_object($row) || ! property_exists($row, 'migration')) {
            throw new RuntimeException('Migration berjalan tanpa meninggalkan satu baris pun riwayat.');
        }

        return (string) $row->migration;
    }

    /**
     * Menutup operasi sebagai gagal dan menurunkan environment ke `degraded`.
     *
     * Constraint `environment_operations_gagal_beralasan` menolak baris gagal tanpa alasan, jadi
     * pesan kosong pun diganti nama kelas pengecualiannya — sebuah baris yang ditolak database di
     * sini akan menelan sebab kegagalan yang sebenarnya dan menggantinya dengan sebab palsu.
     */
    private function markFailed(Environment $environment, EnvironmentOperation $operation, string $step, Throwable $e): void
    {
        $this->closeOperationAsFailed($operation, $step, $e);

        $environment->update(['status' => 'degraded']);
    }

    /** Mengubah sepotong slug menjadi bagian identifier yang sah, tanpa membuatnya unik. */
    private static function sanitize(string $text): string
    {
        $clean = preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($text)) ?? '';

        return trim($clean, '_');
    }
}
