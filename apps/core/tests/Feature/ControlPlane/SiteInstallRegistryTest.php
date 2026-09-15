<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Models\Client;
use App\Models\Environment;
use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Registry pemasangan satu perintah menolak keadaan terlarang di PostgreSQL, bukan di konsol.
 *
 * Tabel-tabel ini ditulis konsol operator dan agen situs lewat konsol — keduanya di luar Core. Aturan
 * yang hanya dijaga salah satu penulis lolos pada penulis berikutnya, jadi yang dibuktikan di sini penolakan database: tiap test menyusun
 * satu keadaan terlarang dan menuntut constraint yang **bernama** menolaknya. Nama constraint ikut
 * diperiksa supaya penolakan karena sebab lain — kolom wajib yang lupa diisi fixture — tidak terbaca
 * sebagai bukti.
 *
 * Tiap penolakan berpasangan dengan keadaan sah yang diterima, karena constraint yang menolak segalanya
 * juga lulus separuh pertama.
 */
final class SiteInstallRegistryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Environment $production;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->tenant('klien-a');
        $this->production = $this->clientServerProduction($this->tenant);
    }

    // ------------------------------------------------------------------ sites.environment_id

    public function test_a_site_points_at_one_environment_and_one_environment_has_one_site(): void
    {
        $this->site($this->tenant, ['name' => 'Server pertama', 'environment_id' => $this->production->id]);

        $this->assertRejectedBy('sites_satu_per_lingkungan', fn () => $this->site($this->tenant, [
            'name' => 'Server kedua',
            'environment_id' => $this->production->id,
        ]));
    }

    public function test_sites_registered_before_today_may_name_no_environment(): void
    {
        $this->site($this->tenant, ['name' => 'Lama satu']);
        $this->site($this->tenant, ['name' => 'Lama dua']);

        $this->assertSame(2, DB::table('sites')->whereNull('environment_id')->count());
    }

    /** Situs tenant A yang menunjuk produksi tenant B akan menerima perintah pasang milik B. */
    public function test_a_site_cannot_point_at_another_tenants_environment(): void
    {
        $other = $this->tenant('klien-b');
        $othersProduction = $this->clientServerProduction($other);

        $this->assertRejectedBy('sites_lingkungan_milik_tenant_yang_sama', fn () => $this->site($this->tenant, [
            'name' => 'Salah tunjuk',
            'environment_id' => $othersProduction->id,
        ]));
    }

    // ------------------------------------------------------------------ site_operations

    public function test_install_is_a_known_site_operation_and_one_request_per_site_is_enforced(): void
    {
        $site = $this->site($this->tenant, ['name' => 'Server', 'environment_id' => $this->production->id]);

        $this->operation($site, 'install');

        $this->assertRejectedBy('site_operations_satu_permintaan_per_jenis', fn () => $this->operation($site, 'install'));
        $this->assertRejectedBy('site_operations_jenis_dikenal', fn () => $this->operation($site, 'install_everything'));
    }

    /** Hash kata sandi sementara yang tertinggal di operasi tertutup hanya menunggu dibocorkan. */
    public function test_a_password_hash_may_only_live_on_an_open_operation(): void
    {
        $site = $this->site($this->tenant, ['name' => 'Server', 'environment_id' => $this->production->id]);
        $hash = ['admin_password_hash' => '$2y$12$'.str_repeat('a', 53)];

        $open = $this->operation($site, 'install', $hash);
        DB::table('site_operations')->where('id', $open)->update(['status' => 'running', 'started_at' => now(), 'lease_until' => now()->addMinutes(15)]);

        foreach (['succeeded', 'cancelled', 'expired'] as $status) {
            $this->assertRejectedBy('site_operations_hash_sandi_hanya_saat_terbuka', fn () => DB::table('site_operations')
                ->where('id', $open)
                ->update(['status' => $status, 'finished_at' => now(), 'lease_until' => null]));
        }

        $this->assertRejectedBy('site_operations_hash_sandi_hanya_saat_terbuka', fn () => DB::table('site_operations')
            ->where('id', $open)
            ->update(['status' => 'failed', 'failure_message' => 'gagal', 'finished_at' => now()]));

        // Menutup sambil membuang hash-nya diterima.
        DB::table('site_operations')->where('id', $open)->update([
            'status' => 'succeeded',
            'finished_at' => now(),
            'parameters' => DB::raw("parameters - 'admin_password_hash'"),
        ]);

        $this->assertSame('succeeded', DB::table('site_operations')->where('id', $open)->value('status'));
    }

    // ------------------------------------------------------------------ console_settings

    public function test_a_setting_is_one_row_per_key_and_outlives_the_user_who_changed_it(): void
    {
        $operator = User::factory()->create();

        DB::table('console_settings')->insert(['key' => 'source_ref', 'value' => 'main', 'updated_by' => $operator->id]);

        $this->assertNotNull(DB::table('console_settings')->where('key', 'source_ref')->value('updated_at'), 'updated_at terisi sendiri bila penulis lupa.');
        $this->assertRejectedBy('console_settings_pkey', fn () => DB::table('console_settings')->insert(['key' => 'source_ref', 'value' => 'rilis']));

        DB::table('users')->where('id', $operator->id)->delete();

        $this->assertSame('main', DB::table('console_settings')->where('key', 'source_ref')->value('value'));
        $this->assertNull(DB::table('console_settings')->where('key', 'source_ref')->value('updated_by'));
    }

    // ------------------------------------------------------------------ perkakas

    private function tenant(string $slug): Tenant
    {
        $client = Client::create(['legal_name' => 'PT '.$slug, 'slug' => $slug, 'status' => 'active']);

        return Tenant::create(['client_id' => $client->id, 'name' => 'PT '.$slug, 'slug' => $slug, 'status' => 'active']);
    }

    private function clientServerProduction(Tenant $tenant): Environment
    {
        return Environment::create([
            'tenant_id' => $tenant->id,
            'kind' => 'production',
            'name' => 'Produksi',
            'slug' => $tenant->slug,
            'database_name' => null,
            'hosting' => Environment::HOSTING_CLIENT_SERVER,
            'status' => 'provisioning',
            'outbound_allowed' => true,
        ]);
    }

    /** @param  array<string, mixed>  $columns */
    private function site(Tenant $tenant, array $columns): string
    {
        $id = (string) Str::ulid();

        $row = [
            'id' => $id,
            'tenant_id' => $tenant->id,
            'name' => 'Server',
            'profile' => 'managed_on_prem',
            'edition' => 'inti',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // `connectivity` wajib hari ini dan sedang dibuang bersama jalur offline di cabang lain.
        // Fixture ini tidak boleh ikut menentukan urutan kedua cabang itu mendarat.
        if (Schema::hasColumn('sites', 'connectivity')) {
            $row['connectivity'] = 'online';
        }

        DB::table('sites')->insert(array_replace($row, $columns));

        return $id;
    }

    /** @param  array<string, mixed>  $parameters */
    private function operation(string $siteId, string $operation, array $parameters = []): string
    {
        $id = (string) Str::ulid();

        DB::table('site_operations')->insert([
            'id' => $id,
            'site_id' => $siteId,
            'operation' => $operation,
            'parameters' => json_encode((object) $parameters),
            'status' => 'requested',
            'requested_at' => now(),
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function assertRejectedBy(string $constraint, Closure $write): void
    {
        try {
            // Savepoint: penolakan membatalkan seluruh blok transaksi PostgreSQL, dan test ini
            // memeriksa beberapa penolakan berturut-turut di dalam transaksi `RefreshDatabase`.
            DB::transaction(static fn () => $write());
            $this->fail('Registry menerima keadaan yang seharusnya ditolak '.$constraint.'.');
        } catch (QueryException $rejected) {
            $this->assertStringContainsString($constraint, $rejected->getMessage());
        }
    }
}
