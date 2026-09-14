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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
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

    // ------------------------------------------------------------------ pembantu

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
