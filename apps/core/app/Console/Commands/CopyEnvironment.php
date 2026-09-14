<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\HoldsEnvironmentOperation;
use App\Models\Environment;
use App\Models\EnvironmentOperation;
use App\Support\Reporting\ExportStatus;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Menyalin sebuah lingkungan produksi menjadi sandbox baru milik tenant yang sama.
 *
 * Rencananya di `docs/todo/environment-dan-pusat-admin/README.md`, bagian "Operasi Copy". Tiga
 * vendor dengan ukuran tim yang jauh berbeda — Business Central, Odoo, Frappe — sampai pada bentuk
 * yang sama, dan bentuk itulah yang dikerjakan di sini: **salin, lalu lucuti.**
 *
 * ## Kenapa pelucutannya yang menentukan, bukan penyalinannya
 *
 * Menyalin database adalah bagian yang mudah; `pg_dump` sudah mengerjakannya sejak dua puluh tahun
 * lalu. Yang berbahaya justru salinan yang berhasil: ia membawa serta setiap antrean, setiap
 * kredensial, dan setiap pekerjaan tertunda milik produksi — lengkap dengan alamat tujuan yang
 * sama persis. Tanpa pelucutan, "sandbox" adalah proses kedua yang mengirim ulang keputusan
 * produksi ke sistem sungguhan milik pelanggan, beberapa menit sesudah salinannya selesai, tanpa
 * ada yang menekan tombol apa pun.
 *
 * Karena itu urutan di bawah tidak boleh dibalik, dan langkah pelucutan tidak boleh dilewati
 * bahkan ketika penyalinannya dianggap berhasil.
 *
 * ## Tidak ada downtime produksi, dan jangan menambahkannya
 *
 * `pg_dump` membuka satu transaksi *repeatable read* atas database sumber dan membaca seluruhnya
 * dari satu snapshot. Salinannya karena itu konsisten pada satu titik waktu tanpa menghentikan apa
 * pun — tidak ada tabel yang dikunci, tidak ada permintaan pengguna yang tertahan. Menambahkan
 * langkah "bekukan produksi dulu" tidak menambah satu jaminan pun; ia hanya mengganggu pelanggan
 * supaya operasinya terlihat lebih serius.
 *
 * ## `pg_dump` dijalankan sebagai klien lewat jaringan
 *
 * Bukan lewat docker socket. Jalur socket itulah yang dulu membuat worker penempatan tidak pernah
 * bisa berjalan di environment mana pun yang dihasilkan repo ini — kode yang harus berbicara
 * dengan daemon di luar dirinya untuk mengerjakan hal yang sebenarnya bisa ia kerjakan sendiri.
 * Ongkos jalur klien satu paket `postgresql-client` di dalam image, dan itu harga yang jauh lebih
 * murah daripada ketergantungan pada socket yang tidak ada di setiap tempat image ini berjalan.
 *
 * ## Dump ditulis ke berkas, bukan dialirkan langsung ke `pg_restore`
 *
 * `pg_dump ... | pg_restore ...` terlihat lebih rapi dan salah. Pipe menyembunyikan exit code:
 * status yang dilaporkan shell adalah milik perintah terakhir, jadi `pg_dump` yang mati di tengah
 * menghasilkan restore yang "berhasil" atas salinan yang terpotong. Repo ini sudah membayar harga
 * kelas kesalahan itu pada jalur build. Dengan berkas antara, exit code `pg_dump` diperiksa
 * sebelum satu byte pun masuk ke database sasaran.
 *
 * Berkasnya dihapus di `finally`, berhasil maupun gagal. Ia salinan utuh data produksi yang
 * tergeletak di disk aplikasi, dan menyimpannya "kalau-kalau berguna untuk mengulang" adalah cara
 * termurah mengubah satu kegagalan menjadi satu kebocoran.
 *
 * ## Kenapa ia aman dijalankan ulang, tanpa satu pun `DROP DATABASE`
 *
 * Pemulihan di repo ini selalu berbentuk perbaikan maju, dan penyalinan tidak dikecualikan.
 * Kuncinya `pg_restore --single-transaction`: restore yang gagal atau prosesnya dibunuh
 * membatalkan seluruhnya, sehingga yang tertinggal adalah database sasaran yang **kosong** — bukan
 * yang setengah terisi. Percobaan berikutnya tinggal mengisinya.
 *
 * Dan bila prosesnya mati **sesudah** restore selesai tetapi sebelum pelucutan, percobaan
 * berikutnya menemukan database sasaran yang sudah berisi lalu melewati penyalinannya sama sekali
 * — pelucutan, migration, dan pemeriksaan kesehatan ketiganya aman diulang. Tidak ada satu keadaan
 * pun yang menuntut database dibuang lebih dulu, jadi perintah ini tidak pernah membuang apa pun.
 *
 * ## Tiga method yang kembar dengan `ProvisionEnvironment`
 *
 * `createDatabase`, `prepareConnection`, dan `baseConfig` di bawah adalah salinan dari perintah
 * itu. Keduanya seharusnya mengangkat ketiganya ke sebuah trait bersama — persis seperti yang
 * sudah dilakukan terhadap protokol kuncinya — dan itu pekerjaan satu sesi yang memegang kedua
 * berkasnya sekaligus. Dicatat di sini supaya duplikasinya tidak terbaca sebagai kelalaian.
 */
final class CopyEnvironment extends Command
{
    use HoldsEnvironmentOperation;

    protected $signature = 'environment:copy
        {source : Id baris environments produksi yang disalin}
        {--name= : Nama lingkungan sandbox yang lahir dari salinan ini}';

    protected $description = 'Salin sebuah lingkungan produksi menjadi sandbox baru, lalu lucuti salinannya';

    /** Koneksi sementara ke database salinan yang sedang dibangun. */
    private const CONNECTION_TARGET = 'environment_copy';

    /** Koneksi sementara ke database yang sedang disalin; dipakai membaca, tidak pernah menulis. */
    private const CONNECTION_SOURCE = 'environment_copy_source';

    /**
     * Koneksi kedua ke database pusat, dipakai hanya untuk `CREATE DATABASE`.
     *
     * PostgreSQL menolak `CREATE DATABASE` di dalam blok transaksi, dan koneksi bawaan sangat
     * mungkin sedang berada di dalam satu — di suite test ia selalu begitu.
     */
    private const CONNECTION_MAINTENANCE = 'copy_maintenance';

    /** Nama sandbox bila operator tidak menyebutkan satu pun. */
    private const DEFAULT_NAME = 'Sandbox';

    /**
     * Status sasaran yang boleh dilanjutkan oleh percobaan berikutnya.
     *
     * `active` sengaja di luar daftar, dan itu penjagaan yang paling menentukan di seluruh berkas
     * ini: menyalin ke atas sandbox yang sedang dipakai orang menimpa pekerjaannya tanpa satu pun
     * peringatan. Menyalin ke dalam sandbox yang sudah hidup memang operasi yang sah — Business
     * Central menyebutnya Copy — tetapi ia operasi tersendiri yang harus meminta persetujuan,
     * bukan akibat sampingan dari mengetik nama yang sama dua kali.
     */
    private const ALLOWED_TARGET_STATUSES = ['copying', 'degraded'];

    /**
     * Berapa kali ukuran database sumber harus tersedia di disk sebelum penyalinan dimulai.
     *
     * Dump berformat custom terkompresi, jadi pada data yang wajar ia jauh lebih kecil daripada
     * databasenya. Angka ini menutup kemungkinan terburuk — data yang memang tidak dapat
     * dimampatkan — beserta sedikit ruang sisa, karena disk yang penuh **saat** dump berjalan
     * menghasilkan berkas terpotong, dan berkas terpotong adalah bentuk kegagalan yang paling
     * mahal dikenali.
     */
    private const DISK_MARGIN = 1.2;

    /** Batas waktu satu proses klien PostgreSQL, dalam detik. */
    private const PROCESS_TIMEOUT_SECONDS = 3600;

    /** Berapa sering tenggat operasi diperpanjang selagi proses panjang berjalan, dalam detik. */
    private const LEASE_RENEWAL_SECONDS = 60;

    /** Berapa baris antrean event diangkat sekali jalan. */
    private const BATCH_SIZE = 1000;

    /** Operasi yang sedang dipegang, disimpan supaya tenggatnya dapat diperpanjang dari mana saja. */
    private ?EnvironmentOperation $operation = null;

    /** Waktu monotonik kapan tenggat boleh diperpanjang lagi. */
    private float $nextRenewalAt = 0.0;

    /**
     * Berapa lama operasi ini boleh memegang kuncinya sebelum boleh direbut.
     *
     * Jauh lebih panjang daripada dua operasi lain, dan itu tetap bukan jawaban yang cukup:
     * `ProvisionEnvironment` sudah menuliskan bahwa penyalinan tidak masuk kategori "operasi yang
     * wajar selesai dalam satu tenggat", dan bahwa ia **harus memperpanjang tenggatnya sendiri
     * selagi berjalan**. Itu dikerjakan di `renewLease()`, dan angka ini hanya menentukan
     * berapa lama sebuah proses yang benar-benar mati menahan produksinya sebelum boleh direbut.
     */
    protected function operationLeaseMinutes(): int
    {
        return 30;
    }

    /**
     * Apakah klien PostgreSQL benar-benar ada di PATH proses ini.
     *
     * Publik karena test membutuhkannya: sebuah test penyalinan yang hijau di mesin tanpa
     * `pg_dump` tidak membuktikan apa pun, dan yang benar adalah dilewati dengan alasan yang
     * terbaca — bukan hijau diam-diam.
     */
    public static function clientsAvailable(): bool
    {
        foreach (['pg_dump', 'pg_restore'] as $tool) {
            try {
                if (! Process::timeout(30)->run([$tool, '--version'])->successful()) {
                    return false;
                }
            } catch (Throwable) {
                // Berkas yang tidak ada tidak selalu menghasilkan exit code; pada sebagian
                // platform `proc_open` sendiri yang gagal. Keduanya berarti hal yang sama.
                return false;
            }
        }

        return true;
    }

    public function handle(): int
    {
        $source = $this->validSource();

        if (! $source instanceof Environment) {
            return self::FAILURE;
        }

        $typed = $this->option('name');
        $name = trim(is_string($typed) && trim($typed) !== '' ? $typed : self::DEFAULT_NAME);
        $slug = Str::slug($name);

        if ($slug === '' || mb_strlen($name) > 100 || mb_strlen($slug) > 120) {
            $this->error('Nama sandbox harus berisi huruf atau angka, paling panjang 100 karakter.');

            return self::FAILURE;
        }

        // 1 — Tolak bila sasarannya bukan tempat yang boleh ditimpa, produksi paling utama.
        $target = $this->allowedTarget($source, $slug, $name);

        if ($target === false) {
            return self::FAILURE;
        }

        // 2 — Tolak bila sudah ada salinan lain berjalan dari produksi yang sama.
        if (! $this->noOtherCopyRunning($source)) {
            return self::FAILURE;
        }

        // 3 — Tolak bila disk menipis.
        if (! $this->hasEnoughDisk($source)) {
            return self::FAILURE;
        }

        if (! $target instanceof Environment) {
            try {
                $target = $this->createTargetRow($source, $name, $slug);
            } catch (QueryException $conflict) {
                // Sandbox lain lahir di sela pemeriksaan di atas dan sisipan ini. Yang memutuskan
                // indeksnya, bukan pemeriksaan tadi — dan yang kalah mendapat penolakan yang terbaca.
                if (! str_contains($conflict->getMessage(), 'environments_satu_per_jenis')) {
                    throw $conflict;
                }

                $this->error('Tenant ini memperoleh sandbox lain di sela pemeriksaan dan penulisan. Periksa daftar lingkungannya sebelum mencoba lagi.');

                return self::FAILURE;
            }
        }

        $operation = $this->openOperation($target, 'copy');

        if (! $operation instanceof EnvironmentOperation) {
            return self::FAILURE;
        }

        $this->operation = $operation;
        $this->nextRenewalAt = hrtime(true) / 1_000_000_000 + self::LEASE_RENEWAL_SECONDS;

        if (! $this->lockSource($operation, $source)) {
            return self::FAILURE;
        }

        return $this->apply($source, $target, $operation);
    }

    /**
     * Menjalankan seluruh langkah beratnya, dan menutup operasinya apa pun yang terjadi.
     *
     * Kegagalan menurunkan sasaran ke `degraded`, bukan membiarkannya `copying`. Bedanya bukan
     * kosmetik: `copying` berarti "ada yang sedang mengerjakannya", dan sasaran yang selamanya
     * berkata begitu adalah sasaran yang tidak pernah dilihat lagi siapa pun. `degraded` berarti
     * "berhenti di tengah, jalankan lagi perintah yang sama" — dan itu memang satu-satunya
     * pemulihan yang ada.
     */
    private function apply(Environment $source, Environment $target, EnvironmentOperation $operation): int
    {
        $step = 'nama-database';
        $file = null;

        try {
            $databaseName = ProvisionEnvironment::databaseName($target);

            $step = 'buat-database';
            $created = $this->createDatabase($databaseName, (string) $source->database_name);
            $this->line($created
                ? sprintf('  database "%s" dibuat.', $databaseName)
                : sprintf('  database "%s" sudah ada; dilanjutkan.', $databaseName));

            $this->prepareConnection(self::CONNECTION_TARGET, $databaseName);
            $this->prepareConnection(self::CONNECTION_SOURCE, (string) $source->database_name);

            $step = 'salin';

            if ($this->alreadyRestored()) {
                $this->line('  database sasaran sudah berisi salinan dari percobaan sebelumnya; dump dilewati.');
            } else {
                if (! self::clientsAvailable()) {
                    throw new RuntimeException(
                        'pg_dump atau pg_restore tidak ada di PATH proses ini. Penyalinan dijalankan '
                        .'sebagai klien lewat jaringan, bukan lewat docker socket, jadi keduanya '
                        .'harus terpasang di dalam image — paket `postgresql-client-17` di '
                        .'`apps/core/Dockerfile`.'
                    );
                }

                $file = $this->dumpFile($target);
                $this->runClient($this->dumpCommand((string) $source->database_name, $file), 'pg_dump');
                $this->line(sprintf('  dump selesai (%s).', $this->humanSize((int) (filesize($file) ?: 0))));

                $this->runClient($this->restoreCommand($databaseName, $file), 'pg_restore');
                $this->line('  restore selesai.');
            }

            $step = 'lucuti';
            $disarmed = $this->disarm();
            $this->reportDisarm($disarmed);

            $step = 'migration';
            $exitCode = $this->callSilent('migrate', ['--database' => self::CONNECTION_TARGET, '--force' => true]);

            if ($exitCode !== self::SUCCESS) {
                throw new RuntimeException(sprintf('Perintah migrate berhenti dengan kode %d.', $exitCode));
            }

            $step = 'periksa-kesehatan';
            $fingerprint = $this->checkHealth();

            $step = 'aktifkan';
            $target->update([
                'database_name' => $databaseName,
                'copied_at' => now(),
                'schema_migrated_at' => now(),
                'schema_fingerprint' => $fingerprint,
                'status' => 'active',
            ]);

            $operation->update([
                'status' => 'succeeded',
                'step' => $step,
                'finished_at' => now(),
                'lease_until' => null,
                'detail' => ['database' => $databaseName, 'sidik' => $fingerprint, 'dilucuti' => $disarmed],
            ]);

            $this->info(sprintf(
                'Sandbox "%s" aktif di database "%s", disalin dari "%s". Sambungan keluarnya mati.',
                $target->slug,
                $databaseName,
                $source->slug,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->closeOperationAsFailed($operation, $step, $e);
            $target->update(['status' => 'degraded']);

            $this->error(sprintf('Penyalinan berhenti di langkah "%s": %s', $step, $e->getMessage()));
            $this->line(sprintf(
                'Sandbox "%s" ditandai degraded. Perbaiki sebabnya lalu jalankan perintah yang sama '
                .'sekali lagi — apa yang sudah tersalin tidak diulang.',
                $target->slug,
            ));

            return self::FAILURE;
        } finally {
            // Salinan utuh data produksi tidak boleh menginap di disk aplikasi, termasuk sesudah
            // kegagalan. Percobaan berikutnya membuat dump baru; itu ongkos yang jauh lebih murah
            // daripada berkas yang tidak ada yang mengingatnya.
            if (is_string($file)) {
                File::delete($file);
            }

            DB::purge(self::CONNECTION_TARGET);
            DB::purge(self::CONNECTION_SOURCE);
        }
    }

    // ------------------------------------------------------------------ penolakan sebelum bekerja

    /**
     * Lingkungan sumber, atau null beserta alasan yang terbaca.
     *
     * Tiga syarat, dan yang ketiga yang paling mudah terlewat: sumber **wajib punya database
     * sendiri**. `database_name` yang kosong berarti lingkungan itu ikut koneksi bawaan — keadaan
     * pooled dan on-prem — dan di sana satu database memuat data banyak tenant sekaligus.
     * Menyalinnya berarti menyerahkan data pelanggan lain ke dalam sandbox milik satu pelanggan.
     * Itu kebocoran, bukan kesalahan teknis, dan ia tidak boleh diperlakukan sebagai kasus tepi.
     */
    private function validSource(): ?Environment
    {
        $id = (string) $this->argument('source');
        $source = Environment::query()->find($id);

        if (! $source instanceof Environment) {
            $this->error(sprintf('Environment "%s" tidak ada di registry.', $id));

            return null;
        }

        if (! $source->produksi()) {
            $this->error(sprintf(
                'Environment "%s" berjenis %s. Yang disalin menjadi sandbox hanya produksi — '
                .'menyalin sandbox menghasilkan salinan dari salinan, dan tidak ada seorang pun '
                .'yang dapat mengatakan data di dalamnya berasal dari kapan.',
                $source->slug,
                $source->kind,
            ));

            return null;
        }

        if ($source->status !== 'active') {
            $this->error(sprintf(
                'Environment "%s" berstatus %s. Hanya yang aktif yang boleh disalin; yang lain '
                .'sedang atau pernah berhenti di tengah sesuatu, dan salinannya akan mewarisi '
                .'keadaan itu tanpa menyebutkannya.',
                $source->slug,
                $source->status,
            ));

            return null;
        }

        if ($source->database_name === null) {
            $this->error(sprintf(
                'Environment "%s" tidak punya database sendiri — ia ikut koneksi bawaan, dan di '
                .'sana satu database memuat data banyak tenant sekaligus. Menyalinnya akan '
                .'menyerahkan data pelanggan lain ke dalam sandbox ini. Pisahkan databasenya lebih '
                .'dulu lewat `environment:provision`.',
                $source->slug,
            ));

            return null;
        }

        return $source;
    }

    /**
     * Sasaran yang boleh ditulisi: sebuah baris yang dilanjutkan, `null` bila belum ada, atau
     * `false` bila ada sesuatu di sana yang tidak boleh disentuh.
     *
     * Di sinilah "tolak bila sasaran produksi" menggigit. Sasaran ditentukan slug, dan slug
     * diturunkan dari `--nama` yang diketik operator — jadi `--nama=Production` pada tenant yang
     * slug produksinya `production` menunjuk **tepat ke database yang sedang dipakai bekerja**.
     * Tanpa pemeriksaan ini, satu kata yang diketik operator sudah cukup untuk menimpanya.
     */
    private function allowedTarget(Environment $source, string $slug, string $name): Environment|false|null
    {
        $existing = Environment::query()
            ->where('tenant_id', $source->tenant_id)
            ->where('slug', $slug)
            ->whereNull('deleted_at')
            ->first();

        if (! $existing instanceof Environment) {
            // Nama baru. Tetapi satu tenant hanya boleh punya satu sandbox hidup — alamatnya
            // `<tenant>.sandbox.<domain>` hanya memuat tenant dan jenis — jadi sandbox lain yang
            // masih hidup menutup jalannya, apa pun namanya.
            $sandbox = Environment::query()
                ->where('tenant_id', $source->tenant_id)
                ->where('kind', 'sandbox')
                ->whereNull('deleted_at')
                ->first();

            if ($sandbox instanceof Environment) {
                $this->error(sprintf(
                    'Tenant ini sudah punya sandbox "%s". Satu tenant hanya boleh punya satu sandbox. '
                    .'Salin ulang ke nama yang sama untuk melanjutkan yang setengah jadi, atau hapus '
                    .'"%s" dulu.',
                    $sandbox->slug,
                    $sandbox->slug,
                ));

                return false;
            }

            return null;
        }

        if ($existing->produksi()) {
            $this->error(sprintf(
                'Nama "%s" menunjuk lingkungan PRODUKSI "%s" milik tenant ini. Penyalinan menolak '
                .'sasaran produksi: seluruh fitur ini ada supaya salinan tidak dapat menyentuh '
                .'yang asli, dan menimpanya adalah kebalikannya. Pakai nama lain.',
                $name,
                $existing->slug,
            ));

            return false;
        }

        if ($existing->id === $source->id) {
            $this->error('Sasaran dan sumber adalah lingkungan yang sama.');

            return false;
        }

        if (! in_array($existing->status, self::ALLOWED_TARGET_STATUSES, true)) {
            $this->error(sprintf(
                'Tenant ini sudah punya lingkungan "%s" berstatus %s. Menyalin ke atasnya akan '
                .'menimpa apa pun yang sedang dikerjakan orang di sana. Pakai nama lain, atau '
                .'hapus dulu yang itu.',
                $existing->slug,
                $existing->status,
            ));

            return false;
        }

        if ($existing->source_environment_id !== $source->id) {
            $this->error(sprintf(
                'Lingkungan "%s" yang setengah jadi itu berasal dari sumber yang berbeda. '
                .'Melanjutkannya dengan sumber ini akan menghasilkan satu database berisi dua '
                .'salinan yang tidak berhubungan. Pakai nama lain.',
                $existing->slug,
            ));

            return false;
        }

        $this->line(sprintf('  melanjutkan sandbox "%s" yang sebelumnya berhenti di tengah.', $existing->slug));

        return $existing;
    }

    /**
     * Menolak bila produksi ini sedang disalin oleh operasi lain yang masih hidup.
     *
     * Yang menegakkannya indeks `environment_operations_satu_salinan_per_sumber`, bukan method
     * ini. Yang dikerjakan di sini dua hal yang memang tidak dapat dikerjakan indeks: menyatakan
     * gagal operasi yang tenggatnya sudah lewat supaya percobaan ini boleh masuk, dan memberi
     * kalimat yang terbaca ketika penolakannya memang benar.
     */
    private function noOtherCopyRunning(Environment $source): bool
    {
        $running = EnvironmentOperation::query()
            ->where('source_environment_id', $source->id)
            ->where('status', 'running')
            ->first();

        if (! $running instanceof EnvironmentOperation) {
            return true;
        }

        if ($running->lease_until !== null && $running->lease_until->isFuture()) {
            $this->error(sprintf(
                'Produksi "%s" sedang disalin oleh operasi %s dan tenggatnya belum lewat. Satu '
                .'salinan pada satu waktu: dua `pg_dump` sekaligus membaca seluruh isi database '
                .'yang sama dua kali, pada server yang melayani semua pelanggan.',
                $source->slug,
                $running->id,
            ));

            return false;
        }

        $running->update([
            'status' => 'failed',
            'finished_at' => now(),
            'lease_until' => null,
            'failure_message' => sprintf(
                'Tenggatnya habis pada %s tanpa pernah ditutup — prosesnya berhenti tanpa sempat '
                .'melaporkan apa pun. Penyalinan berikutnya dari sumber yang sama mengambil alih, '
                .'dan langkah terakhir yang sempat tercapai adalah "%s".',
                (string) $running->getOriginal('lease_until'),
                $running->step ?? 'tidak tercatat',
            ),
        ]);

        $this->warn(sprintf('Penyalinan %s yang tenggatnya sudah lewat ditandai gagal dan diambil alih.', $running->id));

        return true;
    }

    /**
     * Menolak bila disk tidak cukup untuk menampung dumpnya.
     *
     * ::: Yang **tidak** diukur di sini, dan itu harus jelas
     * Yang diperiksa adalah disk tempat berkas dump ditulis, yaitu disk proses ini. Salinan yang
     * direstore mendarat di disk **server PostgreSQL**, dan tidak ada satu pun cara menanyakan
     * sisa ruang di sana lewat SQL biasa — PostgreSQL tidak menyediakannya tanpa ekstensi. Pada
     * susunan compose hari ini keduanya container yang berbeda dengan volume yang berbeda, jadi
     * pemeriksaan ini **tidak** menjamin databasenya muat.
     *
     * Ia tetap ada karena yang diukurnya nyata: disk aplikasi yang penuh di tengah `pg_dump`
     * menghasilkan berkas terpotong, dan itu kegagalan yang paling mahal dikenali. Sisi server
     * adalah pekerjaan control plane, yang memang berada di luar proses ini.
     * :::
     */
    private function hasEnoughDisk(Environment $source): bool
    {
        $folder = $this->dumpFolder();
        $remaining = disk_free_space($folder);

        if ($remaining === false) {
            $this->error(sprintf('Sisa ruang di "%s" tidak dapat dibaca, jadi penyalinan tidak dimulai.', $folder));

            return false;
        }

        $row = DB::connection($this->maintenanceConnection())
            ->selectOne('select pg_database_size(?) as ukuran', [$source->database_name]);

        $size = is_object($row) && property_exists($row, 'ukuran') ? (int) $row->ukuran : 0;
        $needed = (int) ceil($size * self::DISK_MARGIN);

        DB::purge(self::CONNECTION_MAINTENANCE);

        if ($remaining < $needed) {
            $this->error(sprintf(
                'Sisa ruang di "%s" hanya %s, sementara database "%s" berukuran %s dan dumpnya '
                .'butuh sekitar %s. Disk yang penuh di tengah dump menghasilkan berkas terpotong, '
                .'bukan galat yang jelas — jadi penyalinan ditolak sebelum dimulai.',
                $folder,
                $this->humanSize((int) $remaining),
                (string) $source->database_name,
                $this->humanSize($size),
                $this->humanSize($needed),
            ));

            return false;
        }

        $this->line(sprintf(
            '  sumber %s, sisa disk dump %s.',
            $this->humanSize($size),
            $this->humanSize((int) $remaining),
        ));

        return true;
    }

    // ------------------------------------------------------------------ registry

    private function createTargetRow(Environment $source, string $name, string $slug): Environment
    {
        return Environment::create([
            'tenant_id' => $source->tenant_id,
            'kind' => 'sandbox',
            'name' => $name,
            'slug' => $slug,
            'database_name' => null,
            'status' => 'copying',
            'source_environment_id' => $source->id,
            // Ditulis tegas, bukan dibiarkan mengambil bawaan kolom. `environments_keluar_ikut_jenis`
            // memang menolak nilai lain untuk sandbox, dan justru karena itu nilainya ditulis di
            // sini: yang membaca kode ini tidak perlu mencari constraint untuk tahu bahwa sandbox
            // lahir dengan sambungan keluar yang mati.
            'outbound_allowed' => false,
        ]);
    }

    /**
     * Menandai operasi ini sebagai pemegang sumbernya, dan menyerah bila kalah.
     *
     * Kolomnya diisi lewat `UPDATE` terpisah karena `openOperation()` milik trait tidak menerima
     * kolom tambahan, dan trait itu sengaja tidak disentuh — ia memegang protokol kunci yang
     * dipakai tiga perintah. Akibatnya ada jendela selebar satu pernyataan antara sisipan dan
     * pengisian ini; yang menutupnya tetap indeks, bukan urutan, jadi yang kalah memperoleh
     * penolakan yang bersih alih-alih penyalinan kedua yang berjalan diam-diam.
     */
    private function lockSource(EnvironmentOperation $operation, Environment $source): bool
    {
        try {
            $operation->update(['source_environment_id' => $source->id]);

            return true;
        } catch (QueryException $conflict) {
            if (! str_contains($conflict->getMessage(), 'environment_operations_satu_salinan_per_sumber')) {
                throw $conflict;
            }

            $this->closeOperationAsFailed($operation, 'kunci-sumber', new RuntimeException(sprintf(
                'Penyalinan lain dari produksi "%s" menang lebih dulu di sela pemeriksaan dan '
                .'penulisan. Tunggu sampai ia selesai, lalu jalankan perintah ini lagi.',
                $source->slug,
            )));

            $this->error(sprintf('Produksi "%s" keburu dipegang penyalinan lain.', $source->slug));

            return false;
        }
    }

    // ------------------------------------------------------------------ klien PostgreSQL

    /** @return list<string> */
    private function dumpCommand(string $database, string $file): array
    {
        $base = $this->baseConfig();

        return [
            'pg_dump',
            '--host='.(string) ($base['host'] ?? '127.0.0.1'),
            '--port='.(string) ($base['port'] ?? '5432'),
            '--username='.(string) ($base['username'] ?? ''),
            // Tanpa ini `pg_dump` meminta kata sandi di terminal ketika kredensialnya ditolak, dan
            // sebuah perintah penjadwal yang menunggu ketikan menggantung selamanya tanpa pesan.
            '--no-password',
            '--format=custom',
            // Pemilik dan hak akses tidak ikut. Peran yang memiliki objek di database produksi
            // belum tentu ada dengan nama yang sama di sisi sasaran, dan restore yang berhenti
            // karena sebuah GRANT adalah kegagalan yang tidak menjaga apa pun.
            '--no-owner',
            '--no-privileges',
            '--file='.$file,
            '--dbname='.$database,
        ];
    }

    /** @return list<string> */
    private function restoreCommand(string $database, string $file): array
    {
        $base = $this->baseConfig();

        return [
            'pg_restore',
            '--host='.(string) ($base['host'] ?? '127.0.0.1'),
            '--port='.(string) ($base['port'] ?? '5432'),
            '--username='.(string) ($base['username'] ?? ''),
            '--no-password',
            '--no-owner',
            '--no-privileges',
            // Inti dari "aman diulang tanpa membuang apa pun": restore yang gagal di mana pun
            // membatalkan seluruhnya, jadi yang tertinggal adalah database kosong — bukan
            // setengah terisi yang harus dibuang lebih dulu. Ia sekaligus menutup restore yang
            // "berhasil sebagian", bentuk kegagalan yang tidak berbunyi.
            '--single-transaction',
            '--dbname='.$database,
            $file,
        ];
    }

    /**
     * Menjalankan satu proses klien sambil menjaga kunci operasinya tetap hidup.
     *
     * Prosesnya dijalankan tidak-menunggu lalu ditunggui di sini, bukan dijalankan blocking, dan
     * itu bukan gaya. `HoldsEnvironmentOperation` memberi tiap operasi tenggat, dan penyalinan
     * database besar wajar melewatinya — tenggat yang habis selagi prosesnya justru sehat akan
     * membuat percobaan berikutnya merebut kunci dari penyalinan yang sedang berjalan, lalu dua
     * `pg_restore` menulis ke database yang sama. Selama menunggu, tenggatnya diperpanjang.
     *
     * @param  list<string>  $command
     */
    private function runClient(array $command, string $tool): void
    {
        $process = Process::env($this->clientEnvironment())
            ->timeout(self::PROCESS_TIMEOUT_SECONDS)
            ->start($command);

        while ($process->running()) {
            usleep(200_000);
            $this->renewLease();
        }

        $result = $process->wait();

        if ($result->successful()) {
            return;
        }

        $error = trim($result->errorOutput());

        throw new RuntimeException(sprintf(
            '%s berhenti dengan kode %d%s',
            $tool,
            (int) $result->exitCode(),
            $error === '' ? '.' : ': '.mb_substr($error, 0, 800),
        ));
    }

    /**
     * Variabel lingkungan untuk klien PostgreSQL.
     *
     * Kata sandi lewat `PGPASSWORD`, bukan lewat argumen. Argumen proses terbaca siapa pun yang
     * dapat melihat daftar proses di mesin yang sama — termasuk container tetangga pada host yang
     * sama — sementara variabel lingkungan hanya terbaca proses itu sendiri dan yang punya haknya.
     *
     * @return array<string, string>
     */
    private function clientEnvironment(): array
    {
        $base = $this->baseConfig();

        $variables = [
            'PGPASSWORD' => (string) ($base['password'] ?? ''),
            'PGSSLMODE' => (string) ($base['sslmode'] ?? 'prefer'),
            // Klien PostgreSQL menulis pesannya mengikuti locale. Pesan galat yang bentuknya
            // berubah mengikuti setelan mesin adalah pesan yang tidak dapat dicocokkan siapa pun.
            'LC_MESSAGES' => 'C',
        ];

        return array_filter($variables, static fn (string $value): bool => $value !== '');
    }

    /**
     * Memperpanjang tenggat operasi, paling sering sekali per jeda yang ditentukan.
     *
     * `hrtime` dan bukan `now()`: yang diukur di sini selang waktu nyata, dan `now()` dapat
     * dibekukan test mana pun lewat `travelTo`. Penjaga yang berhenti berdetak karena sebuah test
     * membekukan waktunya adalah penjaga yang gagal di tempat yang paling sulit dilacak.
     */
    private function renewLease(): void
    {
        $now = hrtime(true) / 1_000_000_000;

        if ($now < $this->nextRenewalAt || ! $this->operation instanceof EnvironmentOperation) {
            return;
        }

        $this->nextRenewalAt = $now + self::LEASE_RENEWAL_SECONDS;
        $this->operation->update(['lease_until' => now()->addMinutes($this->operationLeaseMinutes())]);
    }

    // ------------------------------------------------------------------ pelucutan

    /**
     * Melucuti salinannya, dan **tanpa** menyaring tenant.
     *
     * Ini kebalikan dari `ConvertEnvironment`, dan perbedaannya disengaja — pelajarannya sama,
     * jawabannya berlawanan karena databasenya berbeda. Di sana pelucutan berjalan pada database
     * yang mungkin dibagi banyak tenant, jadi penyaringan `tenant_id` wajib: melucuti tanpa
     * penyaring akan membatalkan pengiriman event pelanggan yang tidak sedang dikonversi.
     *
     * Di sini databasenya baru saja lahir sebagai salinan eksklusif satu sandbox. Tidak ada
     * pelanggan lain yang menempatinya, dan penyaring `tenant_id` justru berbahaya: baris milik
     * tenant lain yang entah bagaimana ikut tersalin akan **dilewati penyaring** dan tetap
     * bersenjata. Yang benar di sini melucuti seluruh isinya.
     *
     * @return array<string, int>
     */
    private function disarm(): array
    {
        $db = DB::connection(self::CONNECTION_TARGET);

        return [
            'event' => $this->disarmEventQueue($db),
            'ekspor' => $this->disarmExports($db),
            'reservasi' => $this->disarmReservations($db),
            'job' => $this->disarmJobQueue($db),
            'kredensial' => $this->disarmCredentials($db),
            'registry' => $this->disarmCopiedRegistry($db),
        ];
    }

    /**
     * Menandai seluruh event yang belum terbit sebagai terbit, tanpa mengirim satu pun.
     *
     * Ini bahaya paling konkret yang benar-benar ada di repo hari ini, dan ia tidak butuh module
     * baru untuk muncul. `PublishWorkflowEvents` memilih barisnya dengan `published_at IS NULL`
     * **tanpa batas umur sama sekali**, dan alamat tujuannya datang dari config yang sama persis
     * dengan yang dipakai produksi. Tanpa langkah ini, penjadwal di sandbox mengirim **ulang**
     * keputusan produksi ke sistem sungguhan dalam hitungan menit sesudah salinannya selesai.
     *
     * Ditandai terbit, bukan dihapus. Baris yang tidak pernah ditandai akan diambil ulang setiap
     * menit selamanya, dan antrean yang tidak pernah menyusut menyembunyikan baris yang
     * benar-benar gagal terkirim — selain itu penghapusan fisik memang dilarang repo ini.
     *
     * Seluruh jenis event, bukan dua jenis yang penerbit hari ini kenal. Yang berbahaya bukan
     * "jenis yang ada penerbitnya sekarang" melainkan "baris yang ditulis selagi menghubungi luar
     * dilarang"; jenis ketiga yang lahir bulan depan akan memperoleh penerbitnya bulan depan juga.
     */
    private function disarmEventQueue(Connection $db): int
    {
        $count = 0;

        while (true) {
            /** @var list<string> $id */
            $id = $db->table('outbox_events')
                ->whereNull('published_at')
                ->orderBy('occurred_at')
                ->limit(self::BATCH_SIZE)
                ->pluck('id')
                ->all();

            if ($id === []) {
                return $count;
            }

            $count += $db->table('outbox_events')
                ->whereIn('id', $id)
                ->whereNull('published_at')
                ->update(['published_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Menggagalkan ekspor laporan yang masih antre atau sedang berjalan.
     *
     * Worker di sandbox akan mengambilnya lagi, merendernya lagi, lalu menulis berkasnya ke
     * storage dengan nama yang sama — dan storage adalah satu-satunya bagian yang **tidak** ikut
     * tersalin, sehingga yang ditimpa adalah berkas milik produksi.
     *
     * Alasannya ditulis berbahasa Indonesia dan disimpan di barisnya, karena kolom itu yang
     * ditampilkan kepada pengguna yang menunggu ekspornya. "failed" tanpa kalimat adalah cara
     * membuat orang mengulang ekspor yang sama sampai ia menyerah.
     */
    private function disarmExports(Connection $db): int
    {
        return $db->table('report_exports')
            ->whereIn('status', [ExportStatus::QUEUED, ExportStatus::RUNNING])
            ->update([
                'status' => ExportStatus::FAILED,
                'failure_message' => 'Dibatalkan ketika lingkungan ini lahir sebagai salinan. '
                    .'Ekspor yang masih antre di produksi ikut tersalin, dan menjalankannya lagi di '
                    .'sini akan menimpa berkas milik produksi. Jalankan ulang ekspornya dari sini.',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Melepas reservasi nomor yang menggantung, beserta nomor yang dipegangnya.
     *
     * `number-sequences:recover` berjalan di penjadwal dan akan mengaduk baris-baris ini di
     * sandbox: yang `reserved` dan sudah lewat masa berlakunya dinaikkan ke
     * `reconciliation_pending`, lalu menumpuk sebagai backlog rekonsiliasi yang tidak pernah ada
     * yang menyelesaikannya — sementara urutan berkelanjutan tidak boleh melompatinya, sehingga
     * penerbitan nomor di sandbox berhenti pada nomor yang dipegang reservasi milik produksi.
     *
     * Nomornya dikembalikan ke kolam, bukan sekadar reservasinya yang dibatalkan. Reservasi
     * `cancelled` yang barisnya di kolam masih memegang nomor itu adalah nomor yang hilang
     * selamanya, dan pada urutan berkelanjutan satu nomor yang hilang menghentikan seluruhnya.
     *
     * @return int jumlah reservasi yang dilepas
     */
    private function disarmReservations(Connection $db): int
    {
        $pending = ['reserved', 'reconciliation_pending'];

        /** @var list<string> $id */
        $id = $db->table('number_sequence_reservations')
            ->whereIn('status', $pending)
            ->pluck('id')
            ->all();

        if ($id === []) {
            return 0;
        }

        $db->table('number_sequence_continuous_pool')
            ->whereIn('reservation_id', $id)
            ->update(['status' => 'available', 'reservation_id' => null, 'updated_at' => now()]);

        return $db->table('number_sequence_reservations')
            ->whereIn('id', $id)
            ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Mengosongkan antrean job yang ikut tersalin.
     *
     * ::: Rencananya menyebut ini gratis, dan hari ini ia tidak
     * Tabel pelucutan di rencana menulis "Job antrean dan kredensial layanan — tidak ikut tersalin
     * sama sekali; keduanya di sisi pusat". Itu benar untuk pembagian database yang belum berdiri:
     * `coreerp.control_connection` masih kosong, jadi `jobs` hidup di database environment dan ikut
     * tersalin utuh. Barisnya adalah pekerjaan produksi yang **belum** dikerjakan, dan worker
     * sandbox akan mengerjakannya untuk kedua kalinya.
     * :::
     *
     * Dihapus, bukan ditandai. Baris antrean bukan catatan siapa pun — ia instruksi yang belum
     * dijalankan, dan tidak ada kolom yang dapat menyatakan "jangan jalankan yang ini". Larangan
     * hapus fisik repo ini menjaga data pelanggan; ini bukan data, dan ia berada di dalam database
     * yang baru saja lahir.
     */
    private function disarmJobQueue(Connection $db): int
    {
        return $db->table('jobs')->delete();
    }

    /**
     * Membuang kredensial layanan yang ikut tersalin.
     *
     * Rencananya menyebutnya butir pelucutan nomor satu dan menyatakan ongkosnya nol, karena di
     * rancangan itu `app_service_credentials` sudah pindah ke sisi pusat. Perpindahan itu belum
     * terjadi, jadi tabelnya ikut tersalin dan sandbox lahir memegang **token produksi yang masih
     * berlaku** — token yang menerima perintah `internal/v1` atas nama tenant ini.
     *
     * Akibat yang harus diketahui sebelum seseorang mengira ini kelalaian: sandbox tiba tanpa satu
     * pun token layanan, sehingga app module tidak dapat memanggilnya sampai token baru diterbitkan.
     * Itu memang keadaan yang dituju rancangan. Penerbitan ulangnya pekerjaan control plane.
     */
    private function disarmCredentials(Connection $db): int
    {
        return $db->table('app_service_credentials')->delete();
    }

    /**
     * Melucuti registry yang ikut tersalin ke dalam salinannya sendiri.
     *
     * ::: Lubang yang paling mudah terlewat, dan yang paling berbahaya
     * `OwnedByControlPlane` hari ini tidak melakukan apa-apa: `coreerp.control_connection` kosong, jadi
     * `environments` hidup di koneksi bawaan. Salinan sebuah produksi karena itu membawa **salinan
     * tabel `environments` milik produksi**, dan di dalam salinan itu tertulis `kind = 'production'`
     * dengan `outbound_allowed = true`.
     *
     * `ActiveEnvironment` membaca tabel itu. Begitu ada satu jalur yang menjadikan database sandbox
     * sebagai koneksi bawaan — dan itulah persis yang dituju middleware pemilih environment —
     * sandbox akan membaca registry miliknya sendiri, menyimpulkan bahwa ia produksi, lalu
     * membuka seluruh sambungan keluarnya. Seluruh pelucutan di atas menjadi sia-sia oleh satu
     * tabel yang tidak ada yang mengira ikut tersalin.
     * :::
     *
     * Barisnya diturunkan menjadi sandbox, bukan dihapus. Dihapus, `ActiveEnvironment` tidak
     * menemukan apa pun — dan "tidak tahu berarti boleh" adalah aturan yang tertulis di kelas itu,
     * sehingga menghapusnya justru membuka sambungan keluar alih-alih menutupnya. Yang aman adalah
     * registry yang menjawab "bukan produksi" dari sudut mana pun ia dibaca.
     */
    private function disarmCopiedRegistry(Connection $db): int
    {
        // `environments_satu_per_jenis` menolak penurunan di bawah begitu satu tenant punya lebih
        // dari satu baris. Biasanya ia tidak ada di sini: sumber salinan selalu database lingkungan
        // sendiri, dan migration indeks itu melewati koneksi `environment_*`. Tetapi database
        // lingkungan yang pernah dimigrasi lewat koneksi bernama lain membawanya, dan penyalinannya
        // akan jatuh di tengah jalan. Registry di salinan bukan yang berwenang, jadi indeksnya yang
        // dibuang, bukan penurunannya yang dilonggarkan.
        $db->statement('DROP INDEX IF EXISTS environments_satu_per_jenis');

        return $db->table('environments')
            ->where(static function (Builder $query): void {
                $query->where('kind', '!=', 'sandbox')->orWhere('outbound_allowed', true);
            })
            ->update(['kind' => 'sandbox', 'outbound_allowed' => false, 'updated_at' => now()]);
    }

    /** @param  array<string, int>  $disarmed */
    private function reportDisarm(array $disarmed): void
    {
        $this->line(sprintf('  %d event yang belum terbit ditandai terbit tanpa dikirim.', $disarmed['event']));
        $this->line(sprintf('  %d ekspor laporan yang antre ditandai gagal.', $disarmed['ekspor']));
        $this->line(sprintf('  %d reservasi nomor yang menggantung dilepas.', $disarmed['reservasi']));
        $this->line(sprintf('  %d job antrean dibuang.', $disarmed['job']));
        $this->line(sprintf('  %d kredensial layanan dibuang.', $disarmed['kredensial']));
        $this->line(sprintf('  %d baris registry di dalam salinan diturunkan menjadi sandbox.', $disarmed['registry']));
    }

    // ------------------------------------------------------------------ kesehatan

    /**
     * Memeriksa bahwa salinannya benar-benar salinan, dan benar-benar terlucuti.
     *
     * Ia berjalan **sesudah** migration, bukan sesudah pelucutan, dan itu disengaja: sebuah
     * migration yang berjalan di sela keduanya dapat menyisipkan baris baru — dan pemeriksaan yang
     * berhenti sebelum langkah terakhir hanya membuktikan keadaan yang sudah tidak berlaku.
     *
     * Pemeriksaan modul terpasang berjalan ke arah sebaliknya dari yang lain: ia menuntut baris
     * **ada**. Sandbox tanpa module terpasang bukan salinan melainkan database kosong yang
     * kebetulan bermigrasi, dan kegagalan restore yang sunyi terlihat persis seperti itu.
     *
     * @return string sidik skema salinannya
     */
    private function checkHealth(): string
    {
        $target = DB::connection(self::CONNECTION_TARGET);
        $source = DB::connection(self::CONNECTION_SOURCE);

        $remaining = [
            'event yang belum terbit' => $target->table('outbox_events')->whereNull('published_at')->count(),
            'ekspor laporan yang antre' => $target->table('report_exports')
                ->whereIn('status', [ExportStatus::QUEUED, ExportStatus::RUNNING])->count(),
            'reservasi nomor yang menggantung' => $target->table('number_sequence_reservations')
                ->whereIn('status', ['reserved', 'reconciliation_pending'])->count(),
            'job antrean' => $target->table('jobs')->count(),
            'kredensial layanan' => $target->table('app_service_credentials')->count(),
            'baris registry yang masih boleh menghubungi luar' => $target->table('environments')
                ->where('outbound_allowed', true)->count(),
        ];

        foreach ($remaining as $what => $count) {
            if ($count > 0) {
                throw new RuntimeException(sprintf(
                    'Salinan masih memuat %d %s sesudah dilucuti. Sandbox tidak diaktifkan: '
                    .'mengaktifkannya berarti menyalakan penjadwal di atas antrean produksi.',
                    $count,
                    $what,
                ));
            }
        }

        $installedInSource = $source->table('core_module_installations')->count();
        $installedInCopy = $target->table('core_module_installations')->count();

        if ($installedInCopy !== $installedInSource) {
            throw new RuntimeException(sprintf(
                'Sumber punya %d catatan pemasangan module dan salinannya %d. Catatan pemasangan '
                .'tidak pernah disentuh penyalinan ini, jadi selisihnya berarti restorenya tidak utuh.',
                $installedInSource,
                $installedInCopy,
            ));
        }

        /*
         * Diurutkan menurut **nama berkasnya**, bukan menurut `id`.
         *
         * `id` adalah urutan penerapan, dan urutan penerapan tidak sama dengan urutan nama begitu
         * dua migration ditambahkan tidak berurutan — yang terjadi tiap kali dua orang bekerja
         * paralel dan salah satunya memberi timestamp yang lebih awal. Pada database kerja repo ini
         * `..._130000_add_satu_salinan...` benar-benar diterapkan **sesudah**
         * `..._140000_add_purged_at...`.
         *
         * Akibatnya sidik dari `id` tidak dapat dibandingkan dengan sidik image, yang diturunkan
         * dari daftar berkas terurut nama. Dan cacatnya tidak berbunyi: ia melaporkan **setiap**
         * lingkungan tertinggal, selamanya — jadi operator menekan "Perbarui semua", pekerjaannya
         * berjalan, dan angkanya tidak pernah bergerak.
         *
         * Ditemukan dengan membaca jawaban endpointnya pada data sungguhan, bukan oleh test: test
         * menerapkan migrationnya berurutan, jadi kedua urutan itu selalu sepakat di sana.
         *
         * Yang masih tidak tertangkap sidik ini: migration yang **bolong di tengah**. Nama
         * terbesarnya sama, jadi ia terbaca mutakhir. Itu diterima karena `migrate` pada pembaruan
         * berikutnya tetap menerapkan yang bolong — yang salah cuma labelnya, dan cuma sampai
         * pembaruan berikutnya berjalan.
         */
        $row = $target->table('migrations')->orderByDesc('migration')->first();

        if (! is_object($row) || ! property_exists($row, 'migration')) {
            throw new RuntimeException('Salinan tidak punya satu baris pun riwayat migration.');
        }

        return (string) $row->migration;
    }

    // ------------------------------------------------------------------ database dan berkas

    /**
     * Membuat database bila belum ada. Mengembalikan true bila ia benar-benar baru dibuat.
     *
     * Kembaran `ProvisionEnvironment::createDatabase`, dengan satu penjaga tambahan yang hanya masuk
     * akal di sini: nama sasaran dibandingkan juga dengan nama database **sumber**. Sasaran yang
     * ternyata sumber berarti `pg_restore` menulis salinan ke atas produksi yang sedang dibacanya,
     * dan itu satu-satunya langkah di perintah ini yang tidak dapat dibatalkan.
     */
    private function createDatabase(string $name, string $sourceDatabase): bool
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/', $name) !== 1) {
            throw new RuntimeException(sprintf('Nama database "%s" tidak berbentuk identifier yang aman.', $name));
        }

        $base = $this->baseConfig();

        if ($name === (string) ($base['database'] ?? '')) {
            throw new RuntimeException('Nama database salinan sama dengan database pusat; penyalinan dihentikan.');
        }

        if ($name === $sourceDatabase) {
            throw new RuntimeException('Nama database salinan sama dengan databasenya sumber; penyalinan dihentikan.');
        }

        config(['database.connections.'.self::CONNECTION_MAINTENANCE => $base]);
        DB::purge(self::CONNECTION_MAINTENANCE);

        try {
            $koneksi = DB::connection(self::CONNECTION_MAINTENANCE);

            if ($koneksi->selectOne('select 1 from pg_database where datname = ?', [$name]) !== null) {
                return false;
            }

            // `PDO::exec`, bukan `statement()` maupun `unprepared()`. Yang pertama menyiapkan
            // pernyataan lebih dulu, dan protokol extended query PostgreSQL membungkusnya dalam
            // transaksi implisit — persis yang dilarang untuk `CREATE DATABASE`.
            $koneksi->getPdo()->exec(sprintf('CREATE DATABASE "%s"', $name));

            return true;
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'already exists')) {
                return false;
            }

            throw $e;
        } finally {
            DB::purge(self::CONNECTION_MAINTENANCE);
        }
    }

    /**
     * Mendaftarkan sebuah koneksi bernama yang menunjuk database tertentu.
     *
     * `url` dikosongkan karena Laravel mendahulukannya di atas `database` bila ia terisi — dan
     * bila itu terjadi, migration dan pelucutan di atas berjalan ke database pusat.
     *
     * Schema tidak didirikan di sini, berbeda dengan `ProvisionEnvironment`. Dump membawa serta
     * `CREATE SCHEMA` miliknya sendiri, dan mendirikannya lebih dulu hanya akan membuat
     * `pg_restore` berhenti pada objek yang sudah ada.
     */
    private function prepareConnection(string $koneksi, string $database): void
    {
        $konfigurasi = $this->baseConfig();
        $konfigurasi['database'] = $database;
        $konfigurasi['url'] = null;

        config(['database.connections.'.$koneksi => $konfigurasi]);
        DB::purge($koneksi);
    }

    /**
     * Apakah database sasaran sudah berisi salinan dari percobaan sebelumnya.
     *
     * Tabel `migrations` dipakai sebagai penandanya karena ia satu-satunya tabel yang pasti ada
     * pada setiap database environment dan pasti tidak ada pada database yang baru dibuat.
     */
    private function alreadyRestored(): bool
    {
        return DB::connection(self::CONNECTION_TARGET)->getSchemaBuilder()->hasTable('migrations');
    }

    private function dumpFolder(): string
    {
        $folder = storage_path('app/environment-copy');
        File::ensureDirectoryExists($folder);

        return $folder;
    }

    private function dumpFile(Environment $target): string
    {
        return $this->dumpFolder().DIRECTORY_SEPARATOR.$target->id.'.dump';
    }

    /** @return array<string, mixed> */
    private function baseConfig(): array
    {
        $default = (string) config('database.default');
        $konfigurasi = config('database.connections.'.$default);

        if (! is_array($konfigurasi)) {
            throw new RuntimeException(sprintf('Koneksi bawaan "%s" tidak terbaca dari config.', $default));
        }

        /** @var array<string, mixed> $konfigurasi */
        return $konfigurasi;
    }

    /**
     * Mendaftarkan koneksi pemelihara lalu memulangkan namanya.
     *
     * Dipakai pemeriksaan disk, yang berjalan **sebelum** operasinya dibuka dan karena itu belum
     * punya koneksi sasaran mana pun. Ia hanya pernah memanggil `pg_database_size`.
     */
    private function maintenanceConnection(): string
    {
        config(['database.connections.'.self::CONNECTION_MAINTENANCE => $this->baseConfig()]);
        DB::purge(self::CONNECTION_MAINTENANCE);

        return self::CONNECTION_MAINTENANCE;
    }

    /** Ukuran dalam satuan yang terbaca manusia; pesan disk dibaca orang yang sedang panik. */
    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $i = 0;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return sprintf('%.1f %s', $value, $units[$i]);
    }
}
