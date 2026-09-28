<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Actions\NumberSequence\EnsureNumberSequenceDrafts;
use App\Models\Environment;
use App\Models\ModuleInstallation;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\Modules\ModuleManifest;
use App\Support\Modules\ModuleRegistry;
use Database\Seeders\AppCatalogSeeder;
use Database\Seeders\NumberSequenceProfileSeeder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * Tenant server on-prem lahir dengan id yang sudah dicatat admin.erp.
 *
 * Yang dijaga tiga hal. Idnya benar-benar id yang diberikan, dan tenant yang lahir **lengkap** —
 * lingkungan produksi, keanggotaan owner, modul yang dibeli — karena tenant tanpa itu tidak dapat
 * dimasuki siapa pun. Menjalankannya dua kali tidak mengubah apa pun, karena skrip pasang memang
 * dijalankan ulang. Dan masukan yang salah berhenti sebelum satu baris pun ditulis.
 */
final class BootstrapSiteTenantTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_ID = '01j9zq3v6n8m2k4h7g5f3d1c0b';

    private ?string $emptyModulesDirectory = null;

    protected function setUp(): void
    {
        parent::setUp();

        // `app-uji` ada di katalog tetapi bukan modul di image ini. Ia yang membuktikan bahwa yang
        // diberikan isi image, bukan isi katalog.
        $this->seed(AppCatalogSeeder::class);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->emptyModulesDirectory !== null) {
                File::deleteDirectory($this->emptyModulesDirectory);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_it_creates_the_tenant_with_the_given_id_and_entitles_the_modules_in_this_image(): void
    {
        // Jalur yang sama dengan `core-migrate` di server pelanggan: katalog dari manifest dulu.
        $this->seed(NumberSequenceProfileSeeder::class);
        $this->assertSame(Command::SUCCESS, Artisan::call('app:register-manifest'), Artisan::output());

        $modulesInImage = array_values(array_map(
            static fn (ModuleManifest $module): string => $module->id,
            array_filter(app(ModuleRegistry::class)->semua(), static fn (ModuleManifest $module): bool => ! $module->bahanUjiInternal()),
        ));
        // Kriteria yang tidak dapat gagal tidak membuktikan apa pun: tanpa modul, "semua modul
        // diberikan" benar untuk daftar kosong.
        $this->assertNotEmpty($modulesInImage, 'Repo ini seharusnya memuat sedikitnya satu modul bisnis.');

        // Huruf besar, seperti yang mungkin disalin orang dari layar. Tersimpan sebagai huruf kecil.
        [$exitCode, $output] = $this->bootstrap(['--tenant-id' => Str::upper(self::TENANT_ID)]);

        $this->assertSame(Command::SUCCESS, $exitCode, $output);

        $tenant = Tenant::query()->sole();
        $this->assertSame(self::TENANT_ID, $tenant->id);
        $this->assertSame('Apotek Sejahtera', $tenant->name);

        $production = Environment::query()->where('tenant_id', self::TENANT_ID)->sole();
        $this->assertSame('production', $production->kind);
        $this->assertSame('active', $production->status);

        $owner = User::query()->where('email', 'admin@apotek.test')->sole();
        $this->assertTrue(
            TenantMembership::query()
                ->where('tenant_id', self::TENANT_ID)
                ->where('user_id', $owner->id)
                ->where('system_role', 'owner')
                ->where('status', 'active')
                ->exists(),
        );
        $this->assertTrue($owner->must_change_password, 'Kata sandi sementara wajib diganti saat pertama masuk.');

        // Kata sandi yang dicetak memang kata sandi akun itu — bukan string acak yang tidak dipakai.
        $this->assertTrue(Hash::check($this->printedPassword($output), $owner->password));

        $entitled = DB::table('tenant_app_entitlements')->where('tenant_id', self::TENANT_ID)->orderBy('app_id')->pluck('app_id')->all();
        $expected = $modulesInImage;
        sort($expected);
        $this->assertSame($expected, $entitled, 'Yang diberikan harus persis modul di image — tanpa app-uji dan tanpa modul bahan uji.');

        foreach ($modulesInImage as $moduleId) {
            $this->assertDatabaseHas('core_module_installations', [
                'tenant_id' => self::TENANT_ID,
                'module_id' => $moduleId,
                'status' => ModuleInstallation::STATUS_INSTALLED,
            ]);
        }
    }

    public function test_a_second_run_with_the_same_tenant_id_succeeds_and_changes_nothing(): void
    {
        $this->useImageWithoutModules();

        [$firstExit, $firstOutput] = $this->bootstrap();
        $this->assertSame(Command::SUCCESS, $firstExit, $firstOutput);
        $this->assertStringContainsString('tidak ada — hanya Core', $firstOutput);
        $this->assertSame(0, DB::table('tenant_app_entitlements')->count(), 'Image tanpa modul tidak memberikan apa pun.');

        $before = $this->rowCounts();
        $passwordHash = User::query()->where('email', 'admin@apotek.test')->value('password');

        [$secondExit, $secondOutput] = $this->bootstrap();

        $this->assertSame(Command::SUCCESS, $secondExit, $secondOutput);
        $this->assertStringContainsString('sudah ada', $secondOutput);
        $this->assertStringNotContainsString('Kata sandi sementara', $secondOutput);
        $this->assertSame($before, $this->rowCounts());
        $this->assertSame($passwordHash, User::query()->where('email', 'admin@apotek.test')->value('password'));
    }

    public function test_an_invalid_ulid_is_rejected_before_anything_is_written(): void
    {
        [$exitCode, $output] = $this->bootstrap(['--tenant-id' => 'tenant-apotek-sejahtera']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('--tenant-id harus berupa ULID', $output);
        $this->assertSame(0, Tenant::query()->count());
        $this->assertSame(0, User::query()->count());
    }

    public function test_every_missing_option_is_named(): void
    {
        [$exitCode, $output] = $this->bootstrap([
            '--tenant-id' => null,
            '--name' => null,
            '--admin-name' => null,
            '--admin-email' => null,
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);

        foreach (['--tenant-id', '--name', '--admin-name', '--admin-email'] as $option) {
            $this->assertStringContainsString(sprintf('Opsi %s wajib diisi.', $option), $output);
        }

        $this->assertSame(0, Tenant::query()->count());
    }

    /** Aturan kedua pintu lain `RegisterBusiness`: email yang sudah punya akun ditolak, tidak digabung. */
    public function test_an_email_that_already_has_an_account_is_rejected(): void
    {
        $this->useImageWithoutModules();
        User::factory()->create(['email' => 'admin@apotek.test']);

        [$exitCode, $output] = $this->bootstrap(['--admin-email' => 'Admin@Apotek.test']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('sudah memiliki akun', $output);
        $this->assertSame(0, Tenant::query()->count());
        $this->assertSame(1, User::query()->count());
    }

    public function test_a_server_that_already_serves_another_tenant_is_refused(): void
    {
        $this->useImageWithoutModules();
        $this->bootstrap(['--tenant-id' => '01j9zq3v6n8m2k4h7g5f3d1c0c', '--admin-email' => 'lama@apotek.test']);

        [$exitCode, $output] = $this->bootstrap();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('sudah memiliki tenant lain', $output);
        $this->assertFalse(Tenant::query()->whereKey(self::TENANT_ID)->exists());
    }

    public function test_modules_missing_from_the_catalog_stop_it_with_the_step_to_take(): void
    {
        // Katalog hanya berisi `app-uji`; modul di image belum didaftarkan.
        [$exitCode, $output] = $this->bootstrap();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('app:register-manifest', $output);
        $this->assertSame(0, Tenant::query()->count());
    }

    /**
     * Langkah sesudah commit gagal, dan kata sandinya tetap dibacakan.
     *
     * Tanpa itu tenant sudah ada, admin pertamanya tidak punya kata sandi yang diketahui siapa pun,
     * dan menjalankan ulang perintahnya hanya menjawab "sudah ada".
     */
    public function test_a_failure_after_the_tenant_is_committed_still_prints_the_password(): void
    {
        $this->useImageWithoutModules();
        $this->app->instance(EnsureNumberSequenceDrafts::class, new class extends EnsureNumberSequenceDrafts
        {
            public function forReadyTenant(string $tenantId): void
            {
                throw new RuntimeException('penyiapan nomor gagal');
            }
        });

        [$exitCode, $output] = $this->bootstrap();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('penyiapan modulnya tidak selesai', $output);

        $owner = User::query()->where('email', 'admin@apotek.test')->sole();
        $this->assertTrue(Hash::check($this->printedPassword($output), $owner->password));
    }

    // ------------------------------------------------------------------ operasi pasang agen

    /**
     * Hash dari admin.erp tersimpan apa adanya, dan kata sandi aslinya benar-benar membukanya.
     *
     * Dua pemeriksaan, dan keduanya perlu. `assertSame` atas hash-nya membuktikan tidak ada yang
     * meng-hash ulang. `Hash::check` atas teks polosnya membuktikan yang tersimpan memang hash yang
     * dapat dimasuki — cast `hashed` yang meng-hash ulang hash itu sebagai teks akan lolos pemeriksaan
     * pertama pun tidak, tetapi penyimpanan yang memotong atau merapikannya dapat lolos yang pertama
     * dan gagal yang kedua.
     */
    public function test_a_password_hash_on_stdin_is_stored_as_is_and_opens_the_account(): void
    {
        $this->useImageWithoutModules();
        $plaintext = 'Sementara-dari-admin.erp-9Qx';
        $hash = password_hash($plaintext, PASSWORD_BCRYPT, ['cost' => 4]);

        [$exitCode, $output] = $this->bootstrapWithStdin($hash."\n");

        $this->assertSame(Command::SUCCESS, $exitCode, $output);

        $owner = User::query()->where('email', 'admin@apotek.test')->sole();
        $this->assertSame($hash, $owner->password, 'Hash dari admin.erp harus tersimpan tanpa di-hash ulang.');
        $this->assertTrue(Hash::check($plaintext, $owner->password), 'Kata sandi yang ditunjukkan admin.erp harus membuka akun ini.');
        $this->assertTrue($owner->must_change_password, 'Kata sandi sementara wajib diganti saat pertama masuk.');

        $this->assertSame(self::TENANT_ID, Tenant::query()->sole()->id);
        $this->assertStringNotContainsString($hash, $output, 'Hash tidak boleh dicetak.');
        $this->assertStringNotContainsString($plaintext, $output);
        $this->assertStringNotContainsString('Kata sandi sementara', $output, 'Jalur hash tidak mengetahui kata sandinya, jadi tidak ada yang boleh dicetak sebagai kata sandi.');
    }

    public function test_something_that_is_not_a_bcrypt_hash_is_refused_without_being_printed(): void
    {
        $this->useImageWithoutModules();

        foreach ([
            'kata-sandi-polos-yang-salah-alamat',
            // `$2b$` sah bagi bcrypt, tetapi tidak dikenali `Hash::isHashed()` — cast `hashed` akan
            // meng-hash-nya ulang sebagai teks polos.
            '$2b$10$'.str_repeat('a', 53),
            '$2y$10$'.str_repeat('a', 52),
            '',
        ] as $stdin) {
            [$exitCode, $output] = $this->bootstrapWithStdin($stdin);

            $this->assertSame(Command::FAILURE, $exitCode, 'Diterima: '.$stdin);
            $this->assertStringContainsString('bukan hash bcrypt', $output);

            if ($stdin !== '') {
                $this->assertStringNotContainsString($stdin, $output, 'Isi stdin yang ditolak tidak boleh dicetak.');
            }
        }

        $this->assertSame(0, Tenant::query()->count());
        $this->assertSame(0, User::query()->count());
    }

    /**
     * Hash yang biayanya melebihi setelan server ini ditolak sebelum satu baris pun ditulis.
     *
     * Cast `hashed` menolaknya juga, tetapi dari dalam `User::create` — di tengah transaksi, dengan
     * pesan bahasa Inggris yang tidak menyebut setelan mana yang tidak cocok. `BCRYPT_ROUNDS` suite
     * ini 4, jadi biaya 10 melebihinya.
     */
    public function test_a_hash_costlier_than_this_server_allows_is_refused_readably(): void
    {
        $this->useImageWithoutModules();

        [$exitCode, $output] = $this->bootstrapWithStdin(password_hash('apa-saja', PASSWORD_BCRYPT, ['cost' => 10]));

        $this->assertSame(Command::FAILURE, $exitCode, $output);
        $this->assertStringContainsString('BCRYPT_ROUNDS', $output);
        $this->assertSame(0, Tenant::query()->count());
        $this->assertSame(0, User::query()->count());
    }

    public function test_rerunning_the_install_with_the_same_owner_changes_nothing(): void
    {
        $this->useImageWithoutModules();
        $hash = password_hash('pertama', PASSWORD_BCRYPT, ['cost' => 4]);

        $this->assertSame(Command::SUCCESS, $this->bootstrapWithStdin($hash)[0]);
        $before = $this->rowCounts();

        // Agen mengulang operasinya dengan hash baru — admin.erp membuat kata sandi baru tiap kali
        // perintah pasang dibuat ulang. Owner yang sudah ada tetap memegang kata sandi pertamanya.
        [$exitCode, $output] = $this->bootstrapWithStdin(password_hash('kedua', PASSWORD_BCRYPT, ['cost' => 4]), [
            '--admin-email' => 'Admin@Apotek.test',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode, $output);
        $this->assertStringContainsString('sudah ada', $output);
        $this->assertSame($before, $this->rowCounts());
        $this->assertSame($hash, User::query()->where('email', 'admin@apotek.test')->value('password'));
    }

    public function test_the_same_tenant_id_with_a_different_owner_is_refused(): void
    {
        $this->useImageWithoutModules();
        $this->assertSame(Command::SUCCESS, $this->bootstrapWithStdin(password_hash('pertama', PASSWORD_BCRYPT, ['cost' => 4]))[0]);
        $before = $this->rowCounts();

        [$exitCode, $output] = $this->bootstrapWithStdin(password_hash('kedua', PASSWORD_BCRYPT, ['cost' => 4]), [
            '--admin-email' => 'orang-lain@apotek.test',
        ]);

        $this->assertSame(Command::FAILURE, $exitCode, $output);
        $this->assertStringContainsString('owner-nya bukan orang-lain@apotek.test', $output);
        $this->assertStringNotContainsString('sudah ada. Tidak ada yang diubah', $output);
        $this->assertSame($before, $this->rowCounts());
        $this->assertFalse(User::query()->where('email', 'orang-lain@apotek.test')->exists());
    }

    public function test_an_app_that_is_not_a_module_in_this_image_is_refused_by_name(): void
    {
        $this->useImageWithoutModules();

        [$exitCode, $output] = $this->bootstrapWithStdin(password_hash('apa-saja', PASSWORD_BCRYPT, ['cost' => 4]), [
            '--app' => ['human-resources', 'management-aset'],
        ]);

        $this->assertSame(Command::FAILURE, $exitCode, $output);
        $this->assertStringContainsString('tidak ada sebagai modul di image ini: human-resources, management-aset', $output);
        $this->assertSame(0, Tenant::query()->count());
        $this->assertSame(0, User::query()->count());
    }

    /**
     * Dengan `--app`, yang diberikan persis yang dibeli — bukan seluruh isi image.
     *
     * Image pengembangan memuat setiap modul, jadi pembeda yang dibuktikan di sini nyata: modul lain
     * ada di image tetapi tidak diberikan, dan tidak dipasang.
     */
    public function test_the_bought_apps_are_entitled_and_installed_and_nothing_else(): void
    {
        $this->seed(NumberSequenceProfileSeeder::class);
        $this->assertSame(Command::SUCCESS, Artisan::call('app:register-manifest'), Artisan::output());

        $inImage = array_map(static fn (ModuleManifest $module): string => $module->id, app(ModuleRegistry::class)->semua());
        $this->assertContains('human-resources', $inImage);
        $this->assertContains('management-aset', $inImage, 'Test ini butuh modul kedua di image supaya "hanya yang dibeli" dapat gagal.');

        [$exitCode, $output] = $this->bootstrapWithStdin(password_hash('apa-saja', PASSWORD_BCRYPT, ['cost' => 4]), [
            '--app' => ['human-resources'],
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode, $output);
        $this->assertSame(
            ['human-resources'],
            DB::table('tenant_app_entitlements')->where('tenant_id', self::TENANT_ID)->orderBy('app_id')->pluck('app_id')->all(),
        );
        $this->assertDatabaseHas('core_module_installations', [
            'tenant_id' => self::TENANT_ID,
            'module_id' => 'human-resources',
            'status' => ModuleInstallation::STATUS_INSTALLED,
        ]);
        $this->assertDatabaseMissing('core_module_installations', ['tenant_id' => self::TENANT_ID, 'module_id' => 'management-aset']);
    }

    /**
     * Tenant yang hanya membeli Core tidak diberi modul apa pun, walaupun image membawa semuanya.
     *
     * Agen tidak dapat menyebut "tidak ada app" selain dengan tidak mengirim `--app` sama sekali. Pada
     * jalur tangan yang lama, itu berarti seluruh modul di image; pada jalur operasi `install` itu
     * berarti daftar kosong dari admin.erp, dan menafsirkannya sebagai "semua" membuat entitlement di
     * server klien tidak lagi sama dengan yang dibeli.
     */
    public function test_an_install_without_any_app_entitles_nothing_even_when_the_image_carries_modules(): void
    {
        $this->seed(NumberSequenceProfileSeeder::class);
        $this->assertSame(Command::SUCCESS, Artisan::call('app:register-manifest'), Artisan::output());
        $this->assertNotSame([], app(ModuleRegistry::class)->semua(), 'Test ini butuh image yang membawa modul supaya "tidak ada app" dapat gagal.');

        [$exitCode, $output] = $this->bootstrapWithStdin(password_hash('apa-saja', PASSWORD_BCRYPT, ['cost' => 4]));

        $this->assertSame(Command::SUCCESS, $exitCode, $output);
        $this->assertSame(0, DB::table('tenant_app_entitlements')->where('tenant_id', self::TENANT_ID)->count());
        $this->assertSame(0, DB::table('core_module_installations')->where('tenant_id', self::TENANT_ID)->count());
    }

    // ------------------------------------------------------------------ pembantu

    /**
     * Menjalankan perintahnya dengan isi stdin, seperti agen yang mengalirkan hash kata sandi.
     *
     * Lewat `CommandTester`, bukan `Artisan::call`: yang kedua membangun `ArrayInput` tanpa aliran,
     * sehingga perintahnya jatuh ke `STDIN` proses PHPUnit — yang di terminal menunggu ketikan, dan di
     * CI kosong. `CommandTester` memasang aliran di memori, dan itu jalur yang sama dengan aliran
     * milik input yang dibaca perintahnya.
     *
     * @param  array<string, string|list<string>|null>  $options
     * @return array{int, string}
     */
    private function bootstrapWithStdin(string $stdin, array $options = []): array
    {
        $command = $this->app->make(Kernel::class)->all()['tenant:bootstrap-site'];
        $tester = new CommandTester($command);
        $tester->setInputs([$stdin]);

        $exitCode = $tester->execute(array_filter(array_replace([
            '--tenant-id' => self::TENANT_ID,
            '--name' => 'Apotek Sejahtera',
            '--admin-name' => 'Admin Apotek',
            '--admin-email' => 'admin@apotek.test',
            '--admin-password-hash-stdin' => true,
        ], $options), static fn (mixed $value): bool => $value !== null), ['interactive' => false]);

        return [$exitCode, $tester->getDisplay()];
    }

    /**
     * Menjalankan perintahnya lewat `Artisan::call`, supaya keluarannya — termasuk kata sandi
     * sementara — dapat dibaca kembali.
     *
     * @param  array<string, string|null>  $options
     * @return array{int, string}
     */
    private function bootstrap(array $options = []): array
    {
        $exitCode = Artisan::call('tenant:bootstrap-site', array_filter(array_replace([
            '--tenant-id' => self::TENANT_ID,
            '--name' => 'Apotek Sejahtera',
            '--admin-name' => 'Admin Apotek',
            '--admin-email' => 'admin@apotek.test',
        ], $options), static fn (?string $value): bool => $value !== null));

        return [$exitCode, Artisan::output()];
    }

    private function printedPassword(string $output): string
    {
        if (preg_match('/Kata sandi sementara\s*: (\S+)/', $output, $match) !== 1) {
            $this->fail('Keluaran perintah tidak memuat kata sandi sementara: '.$output);
        }

        return $match[1];
    }

    /** Image edisi Core saja — bentuk yang sah, dan jauh lebih cepat daripada memasang modul sungguhan. */
    private function useImageWithoutModules(): void
    {
        $this->emptyModulesDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'coreerp-modul-kosong-'.Str::lower(Str::random(12));
        File::ensureDirectoryExists($this->emptyModulesDirectory);

        $this->app->instance(ModuleRegistry::class, new ModuleRegistry($this->emptyModulesDirectory));
    }

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        $counts = [];

        foreach (['clients', 'tenants', 'users', 'environments', 'tenant_memberships', 'tenant_app_entitlements', 'roles', 'role_assignments', 'outbox_events'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
