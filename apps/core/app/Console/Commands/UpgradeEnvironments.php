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
use RuntimeException;
use Throwable;

/**
 * Membawa lingkungan yang sudah hidup ke skema dan daftar module yang berlaku sekarang.
 *
 * Ia menjawab dua pertanyaan yang terasa berbeda dan sebenarnya satu: "bagaimana pembaruan sampai
 * ke database yang sudah dibuat pelanggan", dan "bagaimana app yang baru dibeli terpasang di
 * tempat kerja yang sudah ada". Keduanya dijawab langkah yang sama, karena
 * {@see InstallEntitledModules} memang aman diulang — ia menjalankan migration module yang belum
 * teraplikasi, memasang yang belum ada, dan melewati data awal yang sudah terisi.
 *
 * ## Sasarannya dihitung saat eksekusi, bukan saat perintahnya dipanggil
 *
 * Azure SQL Elastic Jobs menyebutnya *dynamic enumeration*, dan alasannya persis keadaan kita:
 * *"jobs run across all databases that exist in the server or pool at the time of job execution"*,
 * karena pada SaaS database ditambah dan dibuang terus-menerus. Jadi daftarnya dibaca di dalam
 * `handle()`. Lingkungan yang lahir satu menit sesudah tombolnya ditekan ikut, tanpa didaftarkan
 * ke mana pun.
 *
 * ## Kegagalan satu lingkungan tidak menghentikan yang lain
 *
 * Ini satu-satunya keputusan di berkas ini yang **tidak** punya sandaran dokumentasi vendor —
 * Business Central, Elastic Jobs, Power Platform, dan AWS tidak satu pun menyatakannya. Alasannya
 * sendiri: loop yang berhenti di lingkungan ketiga membuat yang keempat sampai terakhir tidak
 * pernah dimigrasi, dan karena penjadwal membuang keluarannya, tidak ada yang tahu sampai sesuatu
 * pecah berbulan-bulan kemudian.
 *
 * Yang menggantikan penghentiannya: kode keluar bukan nol, dan ringkasan yang **menyebut nama**.
 * Keluaran yang hanya berbunyi "3 gagal" memaksa orangnya membuka database untuk tahu yang mana.
 *
 * ## Gagal berarti `maintenance`, bukan `degraded`
 *
 * Keduanya sama-sama tidak dirutekan, tetapi artinya berbeda dan jawaban HTTP-nya berbeda.
 * `degraded` untuk lingkungan yang **penyiapannya** gagal — ia belum pernah hidup, jadi 404 benar.
 * `maintenance` untuk lingkungan yang **pembaruannya** gagal — ia hidup kemarin dan pemiliknya tahu
 * ia ada, jadi 503 benar. Lihat `ResolveEnvironment`.
 *
 * Dan berhenti melayani memang jawaban yang benar di sana, meski terasa keras: kode yang sudah
 * terpasang menuntut skema baru, dan melayaninya di atas skema lama berarti pelanggan bertemu galat
 * yang tidak dapat dijelaskan siapa pun, satu per satu, sepanjang hari.
 */
final class UpgradeEnvironments extends Command
{
    use HoldsEnvironmentOperation;

    protected $signature = 'environment:upgrade
        {environment? : Id satu lingkungan; kosong berarti seluruhnya}
        {--requested-by= : Id user yang meminta; dipakai konsol operator supaya riwayatnya bernama}';

    protected $description = 'Jalankan migration Core dan module ke tiap lingkungan, lalu pasang module yang baru dibeli';

    /**
     * Status yang boleh diperbarui.
     *
     * `provisioning` sengaja di luar daftar — itu milik `environment:provision`, yang membuat
     * databasenya lebih dulu. `copying` juga: ia sedang ditulis proses lain. `degraded` masuk,
     * karena sebuah penyiapan yang mati sesudah databasenya terbentuk memang dapat dilanjutkan
     * dari sini.
     */
    private const UPGRADABLE = ['active', 'maintenance', 'degraded'];

    public function __construct(
        private readonly EnvironmentConnection $connections,
        private readonly InstallEntitledModules $modules,
    ) {
        parent::__construct();
    }

    /**
     * Sama dengan penyiapan, dan sengaja tidak lebih panjang.
     *
     * Yang dijaga tenggat ini bukan operasi yang lambat melainkan operasi yang prosesnya sudah
     * tidak ada. Merebut milik proses yang masih hidup jauh lebih mahal daripada menunggu.
     */
    protected function operationLeaseMinutes(): int
    {
        return 30;
    }

    public function handle(): int
    {
        $one = $this->argument('environment');
        $one = is_string($one) && $one !== '' ? $one : null;

        if ($one !== null && $this->refusedAsClientServer($one)) {
            return self::FAILURE;
        }

        $targets = $this->targets($one);

        if ($targets === []) {
            $this->info('Tidak ada lingkungan yang perlu diperbarui.');

            return self::SUCCESS;
        }

        $succeeded = [];
        $failed = [];
        $skipped = [];

        foreach ($targets as $environment) {
            $result = $this->upgradeOne($environment);

            match ($result) {
                'succeeded' => $succeeded[] = $environment->slug,
                'skipped' => $skipped[] = $environment->slug,
                default => $failed[] = $environment->slug,
            };
        }

        $this->newLine();
        $this->info(sprintf('%d diperbarui.', count($succeeded)));

        if ($skipped !== []) {
            // Dilewati bukan gagal: operasi lain sedang memegang kuncinya, dan itu justru penjaganya
            // bekerja. Tetap disebut namanya supaya operator tahu apa yang belum tersentuh.
            $this->line(sprintf('  dilewati karena sedang sibuk: %s', implode(', ', $skipped)));
        }

        if ($failed !== []) {
            $this->error(sprintf('%d gagal: %s', count($failed), implode(', ', $failed)));
            $this->line('  Alasannya tercatat di `environment_operations`. Perbaiki, lalu jalankan perintah yang sama lagi.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Lingkungan yang akan disentuh, dibaca **sekarang**.
     *
     * Lingkungan yang dihapus lunak dilewati oleh `whereNull('deleted_at')`, dan itu penting: yang
     * sudah diarsipkan tidak boleh dibangunkan oleh sebuah rilis.
     *
     * Yang berjalan di server klien juga dilewati. Rilis di server ini bukan rilisnya — server klien
     * diperbarui agennya sendiri, dengan paket rakitan untuk edisinya — dan `database_name`-nya yang
     * kosong akan membuat langkah module di bawah memasang app tenant itu ke database bersama, lalu
     * menandai lingkungannya `active` di sini.
     *
     * @return list<Environment>
     */
    private function targets(?string $one): array
    {
        $query = Environment::query()
            ->hostedByProvider()
            ->with('tenant:id,name,slug')
            ->whereNull('deleted_at')
            ->whereIn('status', self::UPGRADABLE)
            ->orderBy('tenant_id')
            ->orderBy('kind');

        if ($one !== null) {
            $query->whereKey($one);
        }

        /** @var list<Environment> $rows */
        $rows = $query->get()->all();

        if ($one !== null && $rows === []) {
            $this->error(sprintf(
                'Lingkungan "%s" tidak ada, sudah diarsipkan, atau berstatus yang tidak boleh diperbarui (%s).',
                $one,
                implode(', ', self::UPGRADABLE),
            ));
        }

        return $rows;
    }

    /**
     * Menolak dengan kalimatnya sendiri bila lingkungan yang disebut berjalan di server klien.
     *
     * Saringan di {@see self::targets()} sudah membuangnya, tetapi yang tertinggal hanyalah
     * "tidak ada, sudah diarsipkan, atau berstatus yang tidak boleh diperbarui" dengan kode keluar
     * nol — jawaban yang salah tentang baris yang jelas ada, untuk operator yang menyebutnya dengan
     * id. Sapuan seluruh armada tidak melewati sini; di sana diam memang jawaban yang benar.
     */
    private function refusedAsClientServer(string $id): bool
    {
        $environment = Environment::query()->find($id);

        if (! $environment instanceof Environment || ! $environment->hostedOnClientServer()) {
            return false;
        }

        $this->error($environment->clientServerRefusal('Pembaruan dari server ini'));

        return true;
    }

    /** @return 'succeeded'|'failed'|'skipped' */
    private function upgradeOne(Environment $environment): string
    {
        $this->line(sprintf('%s / %s', $environment->tenant->name ?? $environment->tenant_id, $environment->slug));

        $operation = $this->openOperation($environment, 'migrate');

        if (! $operation instanceof EnvironmentOperation) {
            return 'skipped';
        }

        $step = 'mulai';

        try {
            $database = $environment->database_name;
            $ownDatabase = is_string($database) && $database !== '';

            if ($ownDatabase) {
                $step = 'periksa-database';
                $this->ensureDatabaseExists($database);

                $step = 'migration-core';
                $connection = $this->connections->register('environment_'.$environment->id, $database);
                $exitCode = $this->callSilent('migrate', ['--database' => $connection, '--force' => true]);

                if ($exitCode !== self::SUCCESS) {
                    throw new RuntimeException(sprintf('Perintah migrate berhenti dengan kode %d.', $exitCode));
                }

                $fingerprint = $this->fingerprintOf($connection);
            } else {
                /*
                 * `database_name` kosong berarti lingkungan ini memang tinggal di database pusat —
                 * keadaan produksi hari ini. Migration Corenya sudah dijalankan `php artisan migrate`
                 * biasa sebelum perintah ini, jadi menjalankannya lagi di sini hanya menambah satu
                 * koneksi tanpa menambah satu baris pun.
                 *
                 * Tetapi langkah module **tetap** dijalankan, dan itu bukan kelalaian yang kebetulan
                 * benar: tanpa itu, app yang baru dibeli tidak akan pernah terpasang di produksi —
                 * satu-satunya tempat kerja yang benar-benar dipakai pelanggan hari ini.
                 */
                $step = 'sidik-pusat';
                $fingerprint = $this->fingerprintOf((string) config('database.default'));
                $this->line('  memakai database pusat; migration Core dilewati.');
            }

            $step = 'module';
            $installed = $this->modules->into($environment);
            $this->line($installed === []
                ? '  tidak ada module yang dibeli tenant ini.'
                : sprintf('  module: %s.', implode(', ', $installed)));

            $step = 'aktifkan';
            $environment->update([
                'schema_migrated_at' => now(),
                'schema_fingerprint' => $fingerprint,
                'status' => 'active',
            ]);

            $operation->update([
                'status' => 'succeeded',
                'step' => $step,
                'finished_at' => now(),
                'lease_until' => null,
                'detail' => ['fingerprint' => $fingerprint, 'modules' => $installed],
            ]);

            $this->info(sprintf('  selesai (sidik %s).', $fingerprint));

            return 'succeeded';
        } catch (Throwable $e) {
            $this->closeOperationAsFailed($operation, $step, $e);

            /*
             * `maintenance`, bukan `degraded`, dan bukan pula dibiarkan `active`.
             *
             * Dibiarkan aktif berarti melayani pelanggan di atas skema yang tidak cocok dengan kode
             * yang sudah terpasang — galat satu per satu sepanjang hari, tanpa satu pun yang
             * menyebut sebabnya. Yang benar berhenti melayani, dengan 503 yang menyebut bahwa
             * tempatnya ada dan sedang tidak melayani.
             */
            $environment->update(['status' => 'maintenance']);

            $this->error(sprintf('  gagal di langkah "%s": %s', $step, $e->getMessage()));

            return 'failed';
        } finally {
            // Koneksi ke database lingkungan ditutup sebelum lanjut ke lingkungan berikutnya.
            // Tanpa ini, satu kali jalan atas dua ratus lingkungan meninggalkan dua ratus backend
            // menganggur di PostgreSQL — dan tiap satunya menghalangi `DROP DATABASE` atas
            // database yang dipegangnya.
            DB::purge('environment_'.$environment->id);
        }
    }

    /**
     * Menolak memperbarui lingkungan yang databasenya tidak ada.
     *
     * Terdengar seperti kehati-hatian berlebih, dan justru sebaliknya: **`php artisan migrate --force`
     * membuat database yang hilang tanpa bertanya.** Lihat
     * `Illuminate\Database\Console\Migrations\MigrateCommand::createMissingMySqlOrPgsqlDatabase()` —
     * dengan `--force` ia tidak pernah meminta konfirmasi, ia langsung `CREATE DATABASE`.
     *
     * Artinya satu salah ketik pada `database_name` tidak berakhir sebagai galat melainkan sebagai
     * database baru berisi seluruh skema Core, dan baris registry yang dengan tenang menunjuknya.
     * Yang kedua lebih buruk daripada yang pertama: yang pertama kelihatan, yang kedua tidak.
     *
     * Membuat database adalah pekerjaan `environment:provision`, yang punya penjaganya sendiri —
     * bentuk identifier diperiksa, dan nama yang sama dengan database pusat ditolak. Perintah ini
     * hanya memperbarui yang sudah ada.
     *
     * Ditemukan bukan oleh test melainkan oleh sebuah database bernama
     * `env_database_ini_tidak_pernah_ada_0000` yang benar-benar muncul di `pg_database` — nama yang
     * dikarang sebuah test justru untuk memastikan ia tidak pernah ada. Testnya lulus, dengan alasan
     * yang sama sekali bukan alasan yang ditulis di dalamnya.
     */
    private function ensureDatabaseExists(string $database): void
    {
        $maintenance = $this->connections->maintenance();

        try {
            $found = DB::connection($maintenance)
                ->selectOne('select 1 from pg_database where datname = ?', [$database]);
        } finally {
            DB::purge($maintenance);
        }

        if ($found === null) {
            throw new RuntimeException(sprintf(
                'Database "%s" tidak ada. Lingkungan ini belum pernah disiapkan — jalankan '
                .'`environment:provision` lebih dulu. Perintah ini sengaja tidak membuat database.',
                $database,
            ));
        }
    }

    /**
     * Sidik skema: nama berkas migration terakhir yang teraplikasi di database itu.
     *
     * Bukan kolom versi yang harus dijaga seseorang. Justru karena versinya cuma satu,
     * "tertinggal" adalah fakta yang dihitung dengan membandingkan sidik lingkungan terhadap sidik
     * image — bukan angka yang harus diingat orang menaikkannya.
     */
    private function fingerprintOf(string $connection): string
    {
        // Urut nama berkas, bukan urut `id`. Alasannya di `CopyEnvironment::schemaFingerprint()`:
        // urutan penerapan tidak sama dengan urutan nama, dan sidik dari `id` melaporkan setiap
        // lingkungan tertinggal selamanya.
        $row = DB::connection($connection)->table('migrations')->orderByDesc('migration')->first();

        if (! is_object($row) || ! property_exists($row, 'migration')) {
            throw new RuntimeException('Database itu tidak punya satu baris pun riwayat migration.');
        }

        return (string) $row->migration;
    }
}
