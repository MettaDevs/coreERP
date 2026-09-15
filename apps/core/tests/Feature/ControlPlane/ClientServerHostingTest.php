<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Actions\Modules\DisableModule;
use App\Actions\Modules\InstallModule;
use App\Actions\Modules\UninstallModule;
use App\Console\Commands\PurgeEnvironment;
use App\Jobs\UpgradeEnvironment as UpgradeEnvironmentJob;
use App\Models\Client;
use App\Models\Environment;
use App\Models\EnvironmentOperation;
use App\Models\Tenant;
use App\Support\ControlPlane\ActiveEnvironment;
use App\Support\Modules\TenantScope;
use Closure;
use Database\Seeders\AppCatalogSeeder;
use Database\Seeders\NumberSequenceProfileSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Produksi yang berjalan di server klien tidak pernah dikerjakan Core di server ini.
 *
 * Barisnya ada di registry — tenant, situs, dan riwayat operator menunjuknya — tetapi isinya tidak.
 * Yang membuatnya berbahaya justru kolom yang terlihat paling tidak berbahaya: `database_name`-nya
 * kosong, dan kosong di registry ini berarti "ikut database bawaan". Setiap jalur yang lupa
 * memeriksanya bekerja di database bersama server ini atas nama tenant yang datanya di tempat lain.
 *
 * Dua bentuk dibuktikan, dan pilihannya per jalur:
 *
 * - **disaring** — daftar, armada, sapuan, alamat: lingkungan server klien tidak pernah muncul.
 * - **ditolak** — tindakan yang menyebut satu lingkungan dengan id-nya: kalimatnya menyebut server
 *   klien, dan tidak ada satu baris pun yang tertulis.
 *
 * Tiap test menyusun keadaan yang **akan** dikerjakan seandainya penjaganya tidak ada — status yang
 * diterima, jenis yang cocok — sehingga satu-satunya alasan penolakannya adalah tempat lingkungan itu
 * berjalan. Test yang ditolak karena alasan lain membuktikan alasan lain itu.
 */
final class ClientServerHostingTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-pusat-admin-uji';

    private const REFUSAL = 'berjalan di server klien';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('coreerp.control_plane_token', self::TOKEN);

        $client = Client::create(['legal_name' => 'PT Uji Klien', 'slug' => 'uji-klien', 'status' => 'active']);
        $this->tenant = Tenant::create([
            'client_id' => $client->id,
            'name' => 'PT Uji Klien',
            'slug' => 'ujiklien',
            'status' => 'active',
        ]);
    }

    // ------------------------------------------------------------------ constraint registry

    public function test_hosting_defaults_to_provider_so_every_existing_path_is_unchanged(): void
    {
        $environment = $this->environment('production', 'active');

        $this->assertSame(Environment::HOSTING_PROVIDER, $environment->refresh()->hosting);
        $this->assertFalse($environment->hostedOnClientServer());
    }

    public function test_only_production_may_run_on_a_client_server(): void
    {
        $this->assertRejectedBy('environments_server_klien_hanya_produksi', fn () => $this->environment('demo', 'active', Environment::HOSTING_CLIENT_SERVER));
    }

    public function test_a_client_server_environment_never_has_a_database_on_this_server(): void
    {
        $this->assertRejectedBy('environments_server_klien_hanya_produksi', fn () => Environment::create([
            'tenant_id' => $this->tenant->id,
            'kind' => 'production',
            'name' => 'Produksi',
            'slug' => 'produksi',
            'database_name' => 'env_ujiklien_produksi',
            'hosting' => Environment::HOSTING_CLIENT_SERVER,
            'status' => 'active',
            'outbound_allowed' => true,
        ]));
    }

    public function test_an_unknown_hosting_is_rejected(): void
    {
        $this->assertRejectedBy('environments_hosting_dikenal', fn () => $this->environment('production', 'active', 'di-awan-entah-mana'));
    }

    // ------------------------------------------------------------------ disaring

    /**
     * Statusnya `active` dengan sengaja: itu satu-satunya status yang dirutekan, jadi 404 di sini
     * hanya dapat datang dari tempat lingkungan itu berjalan.
     */
    public function test_its_address_answers_404_whatever_its_status(): void
    {
        config(['coreerp.base_domain' => 'contoh.co.id']);

        $this->clientServerProduction('active');

        $this->get('http://ujiklien.contoh.co.id/login')->assertNotFound();
        $this->assertFalse(app()->bound(ActiveEnvironment::KEY));
    }

    public function test_the_hosts_file_does_not_point_its_address_at_this_machine(): void
    {
        config(['coreerp.base_domain' => 'contoh.co.id']);

        $this->clientServerProduction('active');
        $this->environment('demo', 'active');

        $this->assertSame(Command::SUCCESS, Artisan::call('environment:hosts', ['--bare' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('127.0.0.1 ujiklien.demo.contoh.co.id', $output);
        $this->assertStringNotContainsString('127.0.0.1 ujiklien.contoh.co.id', $output);
    }

    public function test_outbound_is_never_decided_by_it(): void
    {
        $production = $this->clientServerProduction('active');
        $demo = $this->environment('demo', 'active');

        // Diikat eksplisit: tidak dikenali, jadi "tidak tahu".
        $this->app->instance(ActiveEnvironment::KEY, $production->id);
        $this->assertNull(app(ActiveEnvironment::class)->current());

        // Diturunkan dari tenant: yang terpilih demo yang memang berjalan di sini — dan ia menolak.
        // Tanpa saringan, produksi server klien yang terpilih lebih dulu, dan jawabannya "boleh".
        $this->app->forgetInstance(ActiveEnvironment::KEY);
        $this->app->forgetInstance(ActiveEnvironment::class);
        $this->app->instance(TenantScope::KUNCI, $this->tenant->id);

        $active = app(ActiveEnvironment::class);
        $this->assertSame($demo->id, $active->current()?->id);
        $this->assertFalse($active->outboundAllowed());
    }

    public function test_the_fleet_does_not_list_it(): void
    {
        $production = $this->clientServerProduction('active');
        $demo = $this->environment('demo', 'active');

        $ids = collect($this->withToken(self::TOKEN)->getJson('/api/internal/v1/fleet')->assertOk()->json('environments'))->pluck('id')->all();

        $this->assertContains($demo->id, $ids);
        $this->assertNotContains($production->id, $ids);
    }

    public function test_upgrading_the_whole_fleet_does_not_queue_it(): void
    {
        Queue::fake();
        $production = $this->clientServerProduction('active');
        $demo = $this->environment('demo', 'active');

        $response = $this->withToken(self::TOKEN)->postJson('/api/internal/v1/environments/upgrade', ['force' => true])->assertStatus(202);

        // `force`, supaya "sudah mutakhir" tidak ikut menjadi alasan ia tidak diantrekan.
        $this->assertSame([$demo->id], $response->json('queued'));
        Queue::assertNotPushed(UpgradeEnvironmentJob::class, fn (UpgradeEnvironmentJob $job): bool => $job->environmentId === $production->id);
    }

    public function test_the_upgrade_command_over_the_whole_fleet_leaves_it_untouched(): void
    {
        $production = $this->clientServerProduction('active');
        $demo = $this->environment('demo', 'active');

        $this->assertSame(Command::SUCCESS, Artisan::call('environment:upgrade'), Artisan::output());

        $this->assertNotNull($demo->refresh()->schema_fingerprint);
        $this->assertNull($production->refresh()->schema_fingerprint);
        $this->assertSame(0, EnvironmentOperation::query()->where('environment_id', $production->id)->count());
    }

    /**
     * Sapuan menyaringnya sendiri, tidak bersandar pada constraint "server klien hanya produksi".
     *
     * Constraint itu hari ini sudah membuat keadaan yang diuji di sini mustahil, jadi ia dibuang di
     * dalam transaksi test ini — `RefreshDatabase` memulihkannya. Yang dibuktikan: kalau keputusan
     * produk itu kelak berubah, perintah yang menghapus lunak dan membuang database tidak ikut berubah
     * diam-diam bersamanya.
     */
    public function test_the_sweeps_skip_it_even_without_the_registry_constraint(): void
    {
        DB::statement('ALTER TABLE environments DROP CONSTRAINT environments_server_klien_hanya_produksi');

        $expired = $this->environment('demo', 'active', Environment::HOSTING_CLIENT_SERVER, now()->subDay());
        $graceOver = $this->environment('sandbox', 'active', Environment::HOSTING_CLIENT_SERVER, slug: 'salinan');
        DB::table('environments')->where('id', $graceOver->id)->update([
            'status' => 'soft_deleted',
            'deleted_at' => now()->subMonths(2),
            'purge_after' => now()->subMonth(),
        ]);

        $this->assertSame(Command::SUCCESS, Artisan::call('environment:sweep-expired'), Artisan::output());
        $this->assertNull(DB::table('environments')->where('id', $expired->id)->value('deleted_at'));

        $this->assertTrue(PurgeEnvironment::nothingToPurge());
        $this->assertSame(Command::SUCCESS, Artisan::call('environment:purge'), Artisan::output());
        $this->assertNull(DB::table('environments')->where('id', $graceOver->id)->value('purged_at'));

        $this->assertSame(0, EnvironmentOperation::query()->count());
    }

    // ------------------------------------------------------------------ ditolak

    /**
     * `provisioning` adalah status yang diterima penyiapan — dan status lahir produksi server klien.
     * Tanpa penolakan di depan, perintahnya membuat database lebih dulu.
     */
    public function test_provisioning_it_is_refused_before_a_database_is_created(): void
    {
        $production = $this->clientServerProduction('provisioning');

        $this->assertRefusedByCommand('environment:provision', ['environment' => $production->id]);

        $production->refresh();
        $this->assertSame('provisioning', $production->status);
        $this->assertNull($production->database_name);
        $this->assertSame(0, EnvironmentOperation::query()->count());
    }

    public function test_provisioning_it_through_the_internal_api_is_refused_409(): void
    {
        $production = $this->clientServerProduction('provisioning');

        $this->withToken(self::TOKEN)
            ->postJson('/api/internal/v1/environments/'.$production->id.'/provision')
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, self::REFUSAL));

        $this->assertSame(0, EnvironmentOperation::query()->count());
    }

    public function test_upgrading_it_by_name_is_refused_rather_than_answered_as_nothing_to_do(): void
    {
        Queue::fake();
        $production = $this->clientServerProduction('active');

        $this->assertRefusedByCommand('environment:upgrade', ['environment' => $production->id]);

        $this->withToken(self::TOKEN)
            ->postJson('/api/internal/v1/environments/'.$production->id.'/upgrade', ['force' => true])
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, self::REFUSAL));

        Queue::assertNothingPushed();
        $this->assertSame(0, EnvironmentOperation::query()->count());
    }

    public function test_copying_it_into_a_sandbox_is_refused(): void
    {
        $production = $this->clientServerProduction('active');

        $this->assertRefusedByCommand('environment:copy', ['source' => $production->id]);

        $this->assertSame(1, Environment::query()->count(), 'Tidak boleh ada baris sandbox yang lahir.');
    }

    /** Ia sudah produksi. Menjawabnya "tidak ada yang diubah" dengan kode nol menyembunyikan tempatnya. */
    public function test_converting_it_is_refused_rather_than_answered_as_already_production(): void
    {
        $production = $this->clientServerProduction('active');

        $output = $this->assertRefusedByCommand('environment:convert', ['environment' => $production->id]);

        $this->assertStringNotContainsString('sudah berjenis produksi', $output);
    }

    public function test_restoring_it_is_refused(): void
    {
        $production = $this->clientServerProduction('active');
        $this->softDelete($production, now()->addWeek());

        $this->assertRefusedByCommand('environment:restore', ['environment' => $production->id]);

        $this->assertSame('soft_deleted', $production->refresh()->status);
    }

    public function test_purging_it_by_name_is_refused_with_the_reason_that_explains_it(): void
    {
        $production = $this->clientServerProduction('active');
        $this->softDelete($production, now()->subDay());

        $output = $this->assertRefusedByCommand('environment:purge', ['environment' => $production->id]);

        // Produksi juga ditolak, dan kalimatnya menyuruh orang memutuskan nasib datanya. Yang harus
        // terbaca di sini sebab yang sebenarnya.
        $this->assertStringNotContainsString('berjenis produksi', $output);
        $this->assertNull(DB::table('environments')->where('id', $production->id)->value('purged_at'));
    }

    /**
     * Ditolak, bukan disaring — dan yang dibuktikan di sini kenapa itu penting.
     *
     * Tanpa lingkungan yang disebut, aksinya mencari produksi tenant itu. Menyaring produksi server
     * klien dari pencarian itu membuat aksinya jatuh ke database bawaan dan memasang modulenya di
     * sana. Nama module yang dipakai tidak perlu ada: penolakannya berdiri sebelum registry dibaca.
     */
    public function test_module_actions_are_refused_whether_it_is_named_or_found_as_the_production(): void
    {
        $production = $this->clientServerProduction('active');

        $actions = [
            'pasang' => fn (?Environment $target) => app(InstallModule::class)->handle('human-resources', $this->tenant->id, $target),
            'nonaktifkan' => fn (?Environment $target) => app(DisableModule::class)->handle('human-resources', $this->tenant->id, $target),
            'cabut' => fn (?Environment $target) => app(UninstallModule::class)->handle('human-resources', $this->tenant->id, $target),
        ];

        foreach ($actions as $name => $action) {
            foreach (['disebut' => $production, 'dicari' => null] as $how => $target) {
                try {
                    $action($target);
                    $this->fail(sprintf('Aksi %s (%s) atas produksi server klien tidak ditolak.', $name, $how));
                } catch (RuntimeException $refusal) {
                    $this->assertStringContainsString(self::REFUSAL, $refusal->getMessage(), $name.' '.$how);
                }
            }
        }

        $this->assertSame(0, DB::table('core_module_installations')->count());
    }

    public function test_the_module_command_reports_the_refusal(): void
    {
        $production = $this->clientServerProduction('active');

        $this->assertRefusedByCommand('module:install', [
            'module' => 'human-resources',
            'tenant' => $this->tenant->id,
            '--environment' => $production->id,
        ]);
    }

    // ------------------------------------------------------------------ pintu pembuatan tenant

    /**
     * Operator memilih produksi di server klien, dan tenant lahir tanpa satu module pun dipasang di sini.
     *
     * App yang dibeli sungguhan modul — bukan `app-uji` — dengan sengaja. Tanpa modul sungguhan,
     * pemasangan dilewati karena modulnya tidak ada, dan test ini hijau tanpa pernah melewati jalur
     * yang dijaganya. Dengan modul sungguhan, lupa melewatinya berarti `InstallModule` menolak sesudah
     * commit dan pintunya menjawab 500.
     */
    public function test_the_operator_can_create_a_tenant_whose_production_runs_on_a_client_server(): void
    {
        Queue::fake();
        $this->seed(AppCatalogSeeder::class);
        $this->seed(NumberSequenceProfileSeeder::class);
        $this->assertSame(Command::SUCCESS, Artisan::call('app:register-manifest'), Artisan::output());

        $response = $this->withToken(self::TOKEN)->postJson('/api/internal/v1/tenants', $this->tenantPayload([
            'first_environment_hosting' => 'client_server',
            'app_ids' => ['human-resources'],
        ]));

        $response->assertCreated();
        $tenantId = $response->json('tenant_id');

        $production = Environment::query()->where('tenant_id', $tenantId)->sole();
        $this->assertSame($production->id, $response->json('environment_id'));
        $this->assertSame('production', $production->kind);
        $this->assertSame(Environment::HOSTING_CLIENT_SERVER, $production->hosting);
        $this->assertSame('provisioning', $production->status, 'Belum berjalan sampai perintah pasangnya dijalankan.');
        $this->assertNull($production->database_name);

        $this->assertDatabaseHas('tenant_app_entitlements', ['tenant_id' => $tenantId, 'app_id' => 'human-resources', 'status' => 'active']);
        $this->assertSame(0, DB::table('core_module_installations')->where('tenant_id', $tenantId)->count(), 'Module tenant server klien dipasang di servernya, bukan di sini.');
    }

    public function test_without_the_field_the_first_environment_runs_here_as_before(): void
    {
        Queue::fake();
        $this->seed(AppCatalogSeeder::class);

        $response = $this->withToken(self::TOKEN)->postJson('/api/internal/v1/tenants', $this->tenantPayload())->assertCreated();

        $production = Environment::query()->where('tenant_id', $response->json('tenant_id'))->sole();
        $this->assertSame(Environment::HOSTING_PROVIDER, $production->hosting);
        $this->assertSame('active', $production->status);
    }

    public function test_a_client_server_is_only_for_production(): void
    {
        Queue::fake();
        $this->seed(AppCatalogSeeder::class);

        foreach ([
            ['first_environment' => 'demo', 'first_environment_expires_at' => now()->addMonth()->toDateString()],
            ['first_environment' => 'none'],
        ] as $jenis) {
            $this->withToken(self::TOKEN)
                ->postJson('/api/internal/v1/tenants', $this->tenantPayload(['first_environment_hosting' => 'client_server', ...$jenis]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('first_environment_hosting');
        }

        $this->withToken(self::TOKEN)
            ->postJson('/api/internal/v1/tenants', $this->tenantPayload(['first_environment_hosting' => 'awan']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('first_environment_hosting');

        $this->assertSame(1, Tenant::query()->count(), 'Hanya tenant fixture; tidak ada yang lahir dari permintaan yang ditolak.');
    }

    // ------------------------------------------------------------------ perkakas

    private function clientServerProduction(string $status): Environment
    {
        return $this->environment('production', $status, Environment::HOSTING_CLIENT_SERVER);
    }

    private function environment(string $kind, string $status, string $hosting = Environment::HOSTING_PROVIDER, mixed $expiresAt = null, ?string $slug = null): Environment
    {
        return Environment::create([
            'tenant_id' => $this->tenant->id,
            'kind' => $kind,
            'name' => 'Uji '.$kind,
            'slug' => $slug ?? $kind,
            'database_name' => null,
            'hosting' => $hosting,
            'status' => $status,
            'outbound_allowed' => $kind === 'production',
            'expires_at' => $kind === 'demo' ? ($expiresAt ?? now()->addMonth()) : null,
        ]);
    }

    private function softDelete(Environment $environment, mixed $purgeAfter): void
    {
        DB::table('environments')->where('id', $environment->id)->update([
            'status' => 'soft_deleted',
            'deleted_at' => now()->subDays(40),
            'purge_after' => $purgeAfter,
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function assertRefusedByCommand(string $command, array $arguments): string
    {
        $exitCode = Artisan::call($command, $arguments);
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exitCode, $output);
        $this->assertStringContainsString(self::REFUSAL, $output);

        return $output;
    }

    private function assertRejectedBy(string $constraint, Closure $write): void
    {
        try {
            DB::transaction(static fn () => $write());
            $this->fail('Registry menerima keadaan yang seharusnya ditolak '.$constraint.'.');
        } catch (QueryException $rejected) {
            $this->assertStringContainsString($constraint, $rejected->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $changed
     * @return array<string, mixed>
     */
    private function tenantPayload(array $changed = []): array
    {
        return array_replace([
            'legal_name' => 'PT Metta Klien',
            'admin_name' => 'Owner Klien',
            'admin_email' => 'owner@klien.test',
            'app_ids' => ['app-uji'],
        ], $changed);
    }
}
