<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Console\Commands\SweepExpiredEnvironments;
use App\Models\Client;
use App\Models\Environment;
use App\Models\EnvironmentOperation;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ujung daur hidup sebuah lingkungan: kedaluwarsa, dipulihkan, atau benar-benar dibuang.
 *
 * Ketiganya diuji dalam satu berkas karena ketiganya satu rantai — dan rantai itu yang sebenarnya
 * dijaga. Sapuan yang tidak punya pemulihan bukan hapus lunak melainkan hapus keras yang ditunda,
 * dan pembuangan permanen yang tidak pernah dibuktikan menghormati masa tenggang adalah pembuangan
 * yang membuang terlalu cepat tanpa ada yang tahu.
 *
 * Dua aturan dipegang seluruh berkas ini:
 *
 * **Penolakan dibuktikan lewat akibatnya, bukan lewat kode keluarnya.** Sebuah perintah yang
 * memulangkan FAILURE sesudah terlanjur membuang database lulus pemeriksaan kode keluar dan tetap
 * salah. Karena itu tiap jalur merah memeriksa databasenya masih ada, barisnya masih utuh, dan
 * riwayatnya tidak bertambah.
 *
 * **Database sungguhan, bukan nama yang dikarang.** Jalur merah pembuangan memakai database
 * PostgreSQL yang benar-benar dibuat lebih dulu, sehingga "tidak jadi dibuang" adalah fakta yang
 * dibaca dari `pg_database` — bukan dari ketiadaan sesuatu yang memang tidak pernah ada.
 */
class EnvironmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /** Awalan nama database uji; ia yang dipakai membuang sisa-sisanya. */
    private const PREFIX = 'env_ujidaur';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Client::create(['legal_name' => 'PT Uji Daur', 'slug' => 'uji-daur', 'status' => 'active']);
        $this->tenant = Tenant::create([
            'client_id' => $client->id,
            'name' => 'PT Uji Daur',
            'slug' => 'ujidaur',
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropTestDatabases();

        parent::tearDown();
    }

    // ------------------------------------------------------- sapuan: jalur hijau

    public function test_an_expired_demo_is_soft_deleted_together_with_its_purge_schedule(): void
    {
        $demo = $this->make('demo', 'active', 'lewat', now()->subDay());

        $this->artisan('environment:sweep-expired')->assertExitCode(Command::SUCCESS);

        // Ketiganya sekaligus, dan memang tidak dapat sebagian: `environments_hapus_berpasangan`
        // dan `environments_status_hapus_sejalan` menolak setiap kombinasi lainnya.
        $demo->refresh();
        $this->assertSame('soft_deleted', $demo->status);
        $this->assertNotNull($demo->deleted_at);
        $this->assertNotNull($demo->purge_after);
        $this->assertNull($demo->purged_at, 'Hapus lunak tidak boleh menyentuh isinya.');

        // Masa tenggangnya berjalan dari sekarang, bukan dari tanggal kedaluwarsanya. Demo yang
        // sudah lewat setahun tetap memperoleh jendela penuh untuk dipulihkan.
        $this->assertTrue($demo->purge_after->isAfter(now()->addDays(SweepExpiredEnvironments::GRACE_DAYS - 1)));
        $this->assertTrue($demo->purge_after->isBefore(now()->addDays(SweepExpiredEnvironments::GRACE_DAYS + 1)));

        $operation = EnvironmentOperation::query()->where('environment_id', $demo->id)->firstOrFail();
        $this->assertSame('expire', $operation->operation);
        $this->assertSame('succeeded', $operation->status);
        $this->assertNull($operation->lease_until);

        // Status sebelumnya tercatat, dan itu bukan hiasan: barisnya sendiri sudah tidak dapat
        // menyimpannya, dan pemulihan membacanya dari sini.
        $detail = $operation->detail;
        $this->assertIsArray($detail);
        $this->assertSame('active', $detail['status_sebelumnya'] ?? null);
    }

    public function test_a_second_sweep_does_not_resweep_what_is_already_soft_deleted(): void
    {
        $demo = $this->make('demo', 'active', 'lewat', now()->subDay());

        $this->artisan('environment:sweep-expired')->assertExitCode(Command::SUCCESS);
        $graceDays = $demo->refresh()->purge_after?->toIso8601String();

        // Kalau `deleted_at` tidak ikut disaring, sapuan besok akan memundurkan masa tenggang yang
        // sudah berjalan — dan lingkungan yang menunggu dipulihkan tidak pernah sampai ke antrean
        // pembuangan.
        $this->artisan('environment:sweep-expired')->assertExitCode(Command::SUCCESS);

        $this->assertSame($graceDays, $demo->refresh()->purge_after?->toIso8601String());
        $this->assertSame(1, EnvironmentOperation::query()->where('environment_id', $demo->id)->count());
    }

    public function test_the_grace_period_can_be_named_by_the_operator_and_a_nonsensical_one_is_rejected(): void
    {
        $demo = $this->make('demo', 'active', 'tenggang-pendek', now()->subDay());

        // Nol ditolak di depan, sebelum satu baris pun bergerak. Masa tenggang nol hari berarti
        // sapuan dan pembuangan permanen dapat berjalan pada malam yang sama — yaitu menghapus
        // jendela yang menjadi satu-satunya alasan hapus lunak ada.
        $this->artisan('environment:sweep-expired', ['--grace-days' => '0'])->assertExitCode(Command::FAILURE);
        $this->assertNull($demo->refresh()->deleted_at);
        $this->assertSame(0, EnvironmentOperation::query()->count());

        $this->artisan('environment:sweep-expired', ['--grace-days' => '3'])->assertExitCode(Command::SUCCESS);

        $demo->refresh();
        $this->assertSame('soft_deleted', $demo->status);
        $this->assertTrue($demo->purge_after?->isBefore(now()->addDays(4)) ?? false);
        $this->assertTrue($demo->purge_after?->isAfter(now()->addDays(2)) ?? false);
    }

    // ------------------------------------------------------- sapuan: jalur merah

    public function test_an_active_environment_is_not_swept(): void
    {
        $demo = $this->make('demo', 'active', 'masih-berlaku', now()->addDays(20));
        $production = $this->make('production', 'active', 'produksi');
        $sandbox = $this->make('sandbox', 'active', 'sandbox');

        $this->artisan('environment:sweep-expired')->assertExitCode(Command::SUCCESS);

        // Ketiganya, dan masing-masing menutup kesalahan yang berbeda: demo yang disapu terlalu
        // cepat, produksi yang ikut tersapu karena penyaring jenisnya lupa, dan sandbox yang
        // tersapu karena ia kebetulan bukan produksi.
        foreach ([$demo, $production, $sandbox] as $environment) {
            $environment->refresh();
            $this->assertSame('active', $environment->status);
            $this->assertNull($environment->deleted_at);
            $this->assertNull($environment->purge_after);
        }

        $this->assertSame(0, EnvironmentOperation::query()->count(), 'Sapuan yang tidak menyapu apa pun tidak meninggalkan riwayat.');
    }

    public function test_the_sweep_continues_after_one_environment_fails(): void
    {
        $first = $this->make('demo', 'active', 'satu', now()->subDays(3));
        $failed = $this->make('demo', 'active', 'dua', now()->subDays(2));
        $last = $this->make('demo', 'active', 'tiga', now()->subDay());

        // Kegagalan yang sungguhan, ditanam di PostgreSQL, bukan di PHP. Yang diuji justru
        // perilakunya terhadap pernyataan yang ditolak database: tanpa SAVEPOINT, penolakan pada
        // lingkungan kedua membatalkan seluruh blok transaksi dan setiap pernyataan sesudahnya
        // gagal dengan `25P02` — "melanjutkan" berubah menjadi "melanjutkan lalu gagal semuanya".
        $this->plantFailure($failed);

        $this->artisan('environment:sweep-expired')->assertExitCode(Command::FAILURE);

        $this->assertSame('soft_deleted', $first->refresh()->status);
        $this->assertSame(
            'soft_deleted',
            $last->refresh()->status,
            'Lingkungan sesudah yang gagal wajib tetap tersapu; itu seluruh alasan loop ini ada.',
        );

        $failed->refresh();
        $this->assertSame('active', $failed->status, 'Yang gagal tidak boleh setengah terhapus.');
        $this->assertNull($failed->deleted_at);
        $this->assertNull($failed->purge_after);

        // Dan sebabnya dapat dibaca besok pagi. Penjadwal membuang keluaran konsolnya, jadi baris
        // ini satu-satunya yang tersisa.
        $history = EnvironmentOperation::query()->where('environment_id', $failed->id)->firstOrFail();
        $this->assertSame('failed', $history->status);
        $this->assertSame('hapus-lunak', $history->step);
        $this->assertNotNull($history->failure_message);
        $this->assertNull($history->lease_until, 'Operasi yang sudah selesai tidak boleh terlihat masih memegang kunci.');
    }

    public function test_an_environment_under_another_operation_is_skipped_without_stopping_the_sweep(): void
    {
        $busy = $this->make('demo', 'active', 'sibuk', now()->subDays(2));
        $free = $this->make('demo', 'active', 'bebas', now()->subDay());

        EnvironmentOperation::create([
            'environment_id' => $busy->id,
            'operation' => 'copy',
            'status' => 'running',
            'step' => 'salin',
            'started_at' => now(),
            'lease_until' => now()->addMinutes(30),
        ]);

        $this->artisan('environment:sweep-expired')->assertExitCode(Command::FAILURE);

        // Penolakannya datang dari partial unique index, dan perintah ini tidak pernah memiliki
        // operasi itu — jadi ia juga tidak boleh menyentuhnya.
        $this->assertNull($busy->refresh()->deleted_at);
        $this->assertSame(1, EnvironmentOperation::query()->where('environment_id', $busy->id)->count());
        $this->assertSame('running', EnvironmentOperation::query()->where('environment_id', $busy->id)->firstOrFail()->status);

        $this->assertSame('soft_deleted', $free->refresh()->status);
    }

    // ------------------------------------------------------- pemulihan: jalur hijau

    public function test_an_expired_demo_can_be_restored_before_its_grace_period_runs_out(): void
    {
        $demo = $this->make('demo', 'active', 'pulih', now()->subDay());

        $this->artisan('environment:sweep-expired')->assertExitCode(Command::SUCCESS);
        $this->assertSame('soft_deleted', $demo->refresh()->status);

        $this->artisan('environment:restore', ['environment' => $demo->id])->assertExitCode(Command::SUCCESS);

        $demo->refresh();
        $this->assertNull($demo->deleted_at);
        $this->assertNull($demo->purge_after);
        $this->assertSame('active', $demo->status, 'Keadaan sebelum disapu yang dikembalikan, dibaca dari riwayatnya.');

        // Dan ini yang membuat pemulihannya berarti. Tanpa masa berlaku baru, baris yang kembali
        // hidup dengan tanggal berakhir di masa lalu ditemukan lagi oleh sapuan berikutnya dan
        // dihapus lunak lagi — pemulihan yang dibatalkan penjadwal dalam hitungan jam, tanpa ada
        // satu pun orang yang melakukan kesalahan.
        $this->assertTrue($demo->expires_at?->isFuture() ?? false);

        $this->artisan('environment:sweep-expired')->assertExitCode(Command::SUCCESS);
        $this->assertNull($demo->refresh()->deleted_at, 'Yang baru dipulihkan tidak boleh langsung tersapu lagi.');

        $history = EnvironmentOperation::query()
            ->where('environment_id', $demo->id)
            ->where('operation', 'restore')
            ->firstOrFail();
        $this->assertSame('succeeded', $history->status);

        $detail = $history->detail;
        $this->assertIsArray($detail);
        $this->assertSame('active', $detail['status_dipulihkan'] ?? null);
        $this->assertFalse($detail['status_ditebak'] ?? true);
    }

    public function test_restoring_returns_the_state_it_really_had_not_simply_active(): void
    {
        // Sapuan tidak menyaring status, jadi demo yang tidak pernah selesai disiapkan pun ikut
        // tersapu. Memulihkan semuanya menjadi `active` berarti mengangkat lingkungan tanpa
        // database menjadi tempat yang boleh dirutekan, dan kegagalannya muncul di depan
        // penggunanya — bukan di sini.
        $demo = $this->make('demo', 'degraded', 'rusak', now()->subDay());

        $this->artisan('environment:sweep-expired')->assertExitCode(Command::SUCCESS);
        $this->artisan('environment:restore', ['environment' => $demo->id])->assertExitCode(Command::SUCCESS);

        $this->assertSame('degraded', $demo->refresh()->status);
    }

    public function test_without_history_the_restore_falls_back_to_degraded(): void
    {
        // Lingkungan yang dihapus lunak lewat jalur yang tidak mencatat apa pun — termasuk baris
        // yang sudah ada sebelum perintah-perintah ini lahir. `degraded` jawaban yang paling jujur
        // untuk "isinya ada, tetapi tidak ada yang tahu ia sehat", dan `environment:provision`
        // menerimanya apa adanya sehingga jalan keluarnya satu perintah.
        $demo = $this->make('demo', 'active', 'tanpa-jejak', now()->subDay());
        $this->softDeleteDirectly($demo, now()->addDays(10));

        $this->artisan('environment:restore', ['environment' => $demo->id])->assertExitCode(Command::SUCCESS);

        $this->assertSame('degraded', $demo->refresh()->status);
        $this->assertNull($demo->deleted_at);
        $this->assertNull($demo->purge_after);
    }

    public function test_restoring_an_environment_that_is_not_deleted_changes_nothing(): void
    {
        $demo = $this->make('demo', 'active', 'utuh', now()->addDays(10));

        $this->artisan('environment:restore', ['environment' => $demo->id])->assertExitCode(Command::SUCCESS);

        $this->assertSame('active', $demo->refresh()->status);
        $this->assertSame(0, EnvironmentOperation::query()->count(), 'Yang tidak mengerjakan apa-apa tidak meninggalkan riwayat.');
    }

    // ------------------------------------------------------- pemulihan: jalur merah

    public function test_a_grace_period_that_has_run_out_refuses_to_be_restored(): void
    {
        $demo = $this->make('demo', 'active', 'telat', now()->subDays(60));
        $this->softDeleteDirectly($demo, now()->subDay());

        $this->artisan('environment:restore', ['environment' => $demo->id])->assertExitCode(Command::FAILURE);

        // Datanya mungkin masih ada — pembuangannya berjalan terjadwal — tetapi ia sudah masuk
        // antrean, dan pemulihan di jendela itu adalah balapan dengan sebuah DROP DATABASE.
        $demo->refresh();
        $this->assertSame('soft_deleted', $demo->status);
        $this->assertNotNull($demo->deleted_at);
        $this->assertSame(0, EnvironmentOperation::query()->count());
    }

    public function test_what_is_already_purged_refuses_to_be_restored(): void
    {
        $demo = $this->make('demo', 'active', 'sudah-hilang', now()->subDays(60));
        $this->softDeleteDirectly($demo, now()->subDay());

        $this->artisan('environment:purge')->assertExitCode(Command::SUCCESS);
        $this->assertNotNull($demo->refresh()->purged_at);

        // Memulihkannya menghasilkan baris yang tampil di daftar, terlihat sehat, dan tidak punya
        // satu byte pun data.
        $this->artisan('environment:restore', ['environment' => $demo->id])->assertExitCode(Command::FAILURE);

        $demo->refresh();
        $this->assertSame('soft_deleted', $demo->status);
        $this->assertNotNull($demo->purged_at);
        $this->assertSame(0, EnvironmentOperation::query()->where('operation', 'restore')->count());
    }

    // ------------------------------------------------------- pembuangan: jalur merah

    public function test_production_refuses_to_be_purged(): void
    {
        $name = $this->fakeDatabase('produksi');
        $production = $this->make('production', 'active', 'produksi', null, $name);
        $this->softDeleteDirectly($production, now()->subDay());

        // Disebut namanya, bukan sekadar tidak terpilih sapuan. Penolakan yang hanya berbentuk
        // "tidak pernah masuk daftar" adalah penolakan yang hilang pada hari seseorang memanggilnya
        // langsung dari layar operator.
        $this->artisan('environment:purge', ['environment' => $production->id])
            ->assertExitCode(Command::FAILURE);
        $this->artisan('environment:purge')->assertExitCode(Command::SUCCESS);

        $this->assertTrue($this->databaseExists($name), 'Database produksi tidak boleh disentuh, apa pun statusnya.');
        $this->assertNull($production->refresh()->purged_at);
        $this->assertSame(0, EnvironmentOperation::query()->count());
    }

    public function test_production_is_rejected_by_the_database_not_only_by_the_command(): void
    {
        $production = $this->make('production', 'active', 'produksi');
        $this->softDeleteDirectly($production, now()->subDay());

        // Penjagaan yang hanya hidup di kode adalah penjagaan yang hilang pada jalur berikutnya
        // yang lupa memanggilnya. Dibungkus transaksi karena PostgreSQL membatalkan seluruh blok
        // begitu satu pernyataan ditolak — tanpa savepoint, penolakan ini menjatuhkan transaksi
        // milik `RefreshDatabase` dan seluruh sisa test ikut mati.
        try {
            DB::transaction(function () use ($production): void {
                Environment::query()->whereKey($production->id)->update(['purged_at' => now()]);
            });

            $this->fail('Menandai produksi sebagai dibuang permanen seharusnya ditolak database.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('environments_produksi_tidak_dibuang', $e->getMessage());
        }

        $this->assertNull($production->refresh()->purged_at, 'Transaksi test harus selamat lewat savepoint.');
    }

    public function test_purging_without_ever_soft_deleting_is_rejected_by_the_database(): void
    {
        $demo = $this->make('demo', 'active', 'langsung', now()->subDay());

        // Melewati hapus lunak berarti melewati masa tenggang, yaitu melewati satu-satunya jendela
        // tempat sebuah kesalahan masih dapat dibatalkan.
        try {
            DB::transaction(function () use ($demo): void {
                Environment::query()->whereKey($demo->id)->update(['purged_at' => now()]);
            });

            $this->fail('Membuang lingkungan yang belum dihapus lunak seharusnya ditolak database.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('environments_buang_sesudah_hapus_lunak', $e->getMessage());
        }

        $this->assertNull($demo->refresh()->purged_at);
    }

    public function test_a_grace_period_that_has_not_run_out_refuses_to_be_cleared(): void
    {
        $name = $this->fakeDatabase('tenggang');
        $demo = $this->make('demo', 'active', 'tenggang', now()->subDay(), $name);
        $this->softDeleteDirectly($demo, now()->addDays(7));

        $this->artisan('environment:purge', ['environment' => $demo->id])
            ->assertExitCode(Command::FAILURE);
        $this->artisan('environment:purge')->assertExitCode(Command::SUCCESS);

        $this->assertTrue($this->databaseExists($name), 'Isinya masih harus ada selama ia masih dapat dipulihkan.');
        $this->assertNull($demo->refresh()->purged_at);
        $this->assertSame(0, EnvironmentOperation::query()->count());

        // Dan pasangan hijaunya, di test yang sama: penjaga yang menolak apa saja akan lulus
        // pemeriksaan di atas juga, dan hasilnya disk yang tidak pernah kosong.
        $this->softDeleteDirectly($demo, now()->subSecond());

        $this->artisan('environment:purge')->assertExitCode(Command::SUCCESS);

        $this->assertFalse($this->databaseExists($name));
        $this->assertNotNull($demo->refresh()->purged_at);
    }

    public function test_what_is_not_soft_deleted_yet_is_never_purged(): void
    {
        $name = $this->fakeDatabase('hidup');
        $demo = $this->make('demo', 'active', 'hidup', now()->subDay(), $name);

        $this->artisan('environment:purge', ['environment' => $demo->id])
            ->assertExitCode(Command::FAILURE);

        $this->assertTrue($this->databaseExists($name));
        $this->assertNull($demo->refresh()->purged_at);
    }

    // ------------------------------------------------------- pembuangan: jalur hijau

    public function test_the_environment_database_is_really_dropped_and_its_history_remains(): void
    {
        // Jalur yang sebenarnya berjalan di produksi, dan ia tidak dipalsukan: lingkungannya
        // disiapkan sungguhan lewat `environment:provision`, sehingga yang dibuang adalah database
        // berisi seluruh skema Core — bukan database kosong yang dibuat test ini sendiri.
        $demo = $this->make('demo', 'provisioning', 'siap', now()->subDay());

        $this->artisan('environment:provision', ['environment' => $demo->id])->assertExitCode(Command::SUCCESS);

        $name = (string) $demo->refresh()->database_name;
        $this->assertNotSame('', $name);
        $this->assertTrue($this->databaseExists($name));

        $this->artisan('environment:sweep-expired')->assertExitCode(Command::SUCCESS);
        $this->softDeleteDirectly($demo, now()->subSecond());

        $this->artisan('environment:purge')->assertExitCode(Command::SUCCESS);

        $this->assertFalse($this->databaseExists($name), 'Isinya harus benar-benar hilang; ini satu-satunya tempat yang menghapus.');

        $demo->refresh();
        $this->assertNotNull($demo->purged_at);
        $this->assertSame('soft_deleted', $demo->status, 'Nisannya tetap dihapus lunak; tidak ada status baru yang dikarang.');
        $this->assertSame(
            $name,
            $demo->database_name,
            'Nama databasenya tinggal. Kosong sudah berarti "ikut koneksi bawaan" di tabel ini, dan itu kebalikan dari yang sebenarnya.',
        );

        // Riwayatnya utuh, dan itu yang dituntut `restrictOnDelete` pada `environment_operations`.
        // Justru riwayat inilah yang dibutuhkan untuk memahami kenapa sebuah lingkungan dibuang,
        // jadi membuang barisnya lebih dulu berarti membuang persis yang paling dibutuhkan.
        $history = EnvironmentOperation::query()
            ->where('environment_id', $demo->id)
            ->pluck('operation')
            ->all();

        $this->assertContains('provision', $history);
        $this->assertContains('expire', $history);
        $this->assertContains('purge', $history);

        $this->assertTrue(
            Environment::query()->whereKey($demo->id)->exists(),
            'Barisnya tidak pernah dihapus fisik; yang dibuang databasenya.',
        );

        // Dan aman diulang. Percobaan yang mati sesudah drop tetapi sebelum pencatatannya harus
        // dapat diselesaikan dengan menjalankan perintah yang sama.
        $before = EnvironmentOperation::query()->where('environment_id', $demo->id)->count();
        $this->artisan('environment:purge')->assertExitCode(Command::SUCCESS);
        $this->assertSame($before, EnvironmentOperation::query()->where('environment_id', $demo->id)->count());
    }

    // ------------------------------------------------------- penjadwalan

    public function test_the_scheduler_runs_the_sweep_and_holds_the_purge_until_there_is_something_to_purge(): void
    {
        $events = [];

        foreach (app(Schedule::class)->events() as $item) {
            foreach (['environment:sweep-expired', 'environment:purge'] as $command) {
                if (str_contains((string) $item->command, $command)) {
                    $events[$command] = $item;
                }
            }
        }

        $this->assertArrayHasKey('environment:sweep-expired', $events, 'Sapuan kedaluwarsa harus terdaftar di penjadwal.');
        $this->assertArrayHasKey('environment:purge', $events, 'Pembuangan permanen harus terdaftar di penjadwal.');
        $this->assertSame('0 3 * * *', $events['environment:sweep-expired']->expression);
        $this->assertSame('30 3 * * *', $events['environment:purge']->expression);

        // Penjaganya membaca registry, bukan `APP_ENV`. `Schedule::environments()` tidak dapat
        // membedakan on-prem dari SaaS sama sekali — keduanya berjalan dengan `APP_ENV=production`
        // — sementara yang menentukan di sini justru isi database.
        $this->make('demo', 'active', 'terjadwal', now()->addDays(10));
        $this->assertFalse(
            $events['environment:purge']->filtersPass($this->app),
            'Selama tidak ada yang pernah dihapus lunak, perintah yang paling merusak tidak perlu bangun.',
        );

        $this->assertTrue(
            $events['environment:sweep-expired']->filtersPass($this->app),
            'Sapuan tidak diberi penjaga; ia memang tidak berbahaya ketika tidak ada yang perlu disapu.',
        );

        $demo = $this->make('demo', 'active', 'menunggu', now()->subDay());
        $this->softDeleteDirectly($demo, now()->addDays(30));

        $this->assertTrue(
            $events['environment:purge']->filtersPass($this->app),
            'Begitu ada yang menunggu dibuang, penjadwalnya harus jalan.',
        );
    }

    // ------------------------------------------------------- perkakas

    /**
     * Membuat baris environment yang sah menurut seluruh constraint registry.
     *
     * `outbound_allowed` diturunkan dari jenisnya, bukan diterima sebagai parameter:
     * `environments_keluar_ikut_jenis` mengikat keduanya, jadi kombinasi lain memang tidak dapat
     * lahir. Menurunkannya membuat fixture yang salah gagal saat disusun, bukan saat dijalankan.
     */
    private function make(string $kind, string $status, string $slug, ?CarbonInterface $expiresAt = null, ?string $database = null): Environment
    {
        return Environment::create([
            'tenant_id' => $this->tenant->id,
            'kind' => $kind,
            'name' => 'Uji '.$slug,
            'slug' => 'ujidaur-'.$slug,
            'database_name' => $database,
            'status' => $status,
            'outbound_allowed' => $kind === 'production',
            'expires_at' => $kind === 'demo' ? ($expiresAt ?? now()->addDays(30)) : $expiresAt,
        ]);
    }

    /**
     * Menghapus lunak sebuah baris tanpa lewat perintahnya.
     *
     * Dipakai untuk menyusun keadaan yang perintahnya sendiri tidak akan pernah hasilkan — masa
     * tenggang yang sudah habis, produksi yang dihapus lunak, baris tanpa riwayat sama sekali.
     * Ketiganya tetap keadaan yang sah menurut database, dan justru itu sebabnya ia perlu diuji.
     */
    private function softDeleteDirectly(Environment $environment, CarbonInterface $purgeAfter): void
    {
        Environment::query()->whereKey($environment->id)->update([
            'status' => 'soft_deleted',
            'deleted_at' => $environment->deleted_at ?? now(),
            'purge_after' => $purgeAfter,
        ]);

        $environment->refresh();
    }

    /**
     * Menanam kegagalan sungguhan pada satu baris, lewat PostgreSQL.
     *
     * Bukan mock. Yang diuji perilaku perintahnya terhadap **pernyataan yang ditolak database**,
     * dan itu punya sifat yang tidak dimiliki pengecualian PHP biasa: ia membatalkan seluruh blok
     * transaksi, sehingga setiap pernyataan sesudahnya ikut gagal sampai ada yang mundur ke sebuah
     * savepoint. Kegagalan yang dipalsukan di lapisan PHP tidak akan pernah membuktikan itu.
     */
    private function plantFailure(Environment $environment): void
    {
        DB::unprepared(
            <<<'SQL'
                CREATE OR REPLACE FUNCTION uji_jatuhkan_sapuan() RETURNS trigger AS $callback$
                BEGIN
                    RAISE EXCEPTION 'disk penuh saat menulis baris ini';
                END;
                $callback$ LANGUAGE plpgsql;
                SQL
        );

        DB::unprepared(sprintf(
            <<<'SQL'
                CREATE TRIGGER uji_jatuhkan_sapuan_satu_baris
                BEFORE UPDATE ON environments
                FOR EACH ROW WHEN (OLD.id = '%s')
                EXECUTE FUNCTION uji_jatuhkan_sapuan();
                SQL,
            $environment->id,
        ));
    }

    /** Database kosong yang benar-benar ada, supaya "tidak jadi dibuang" dapat dibuktikan. */
    private function fakeDatabase(string $suffix): string
    {
        $name = self::PREFIX.'_'.$suffix.'_'.Str::lower(Str::random(6));

        $this->maintenance()->unprepared(sprintf('CREATE DATABASE "%s"', $name));

        return $name;
    }

    private function databaseExists(string $name): bool
    {
        return $this->maintenance()->selectOne('select 1 from pg_database where datname = ?', [$name]) !== null;
    }

    /**
     * Koneksi kedua ke database pusat.
     *
     * `RefreshDatabase` memegang transaksi pada koneksi bawaan, dan `CREATE DATABASE` maupun
     * `DROP DATABASE` dilarang berada di dalam transaksi. PDO terpisah satu-satunya jalan.
     */
    private function maintenance(): Connection
    {
        $konfigurasi = config('database.connections.'.config('database.default'));
        config(['database.connections.test_maintenance' => $konfigurasi]);

        return DB::connection('test_maintenance');
    }

    /**
     * Membuang seluruh database yang sempat dibuat test ini.
     *
     * `WITH (FORCE)` memutus sesi yang masih menempel — milik perintah maupun milik test ini
     * sendiri. Tanpa itu satu sesi yang lupa ditutup cukup untuk meninggalkan database yatim.
     */
    private function dropTestDatabases(): void
    {
        DB::purge('environment_provisioning');
        DB::purge('environment_purge');

        $rows = $this->maintenance()->select(
            'select datname from pg_database where datname like ? order by datname',
            [self::PREFIX.'%'],
        );

        foreach ($rows as $database) {
            $this->maintenance()->unprepared(sprintf('DROP DATABASE IF EXISTS "%s" WITH (FORCE)', (string) $database->datname));
        }

        DB::purge('test_maintenance');
    }
}
