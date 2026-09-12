<?php

namespace Tests\Feature\ControlPlane;

use App\Models\Client;
use App\Models\Environment;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Keadaan terlarang pada registry environment benar-benar tidak dapat diwakili.
 *
 * Tiap test di sini membuktikan **penolakan**, bukan keberhasilan, dan penolaknya PostgreSQL —
 * bukan validasi aplikasi. Bedanya menentukan: validasi hanya menjaga jalur yang melewatinya,
 * sedangkan constraint menjaga juga perintah artisan, seeder, migration, dan SQL yang diketik
 * tangan seseorang pada pukul dua pagi.
 *
 * Kalau salah satu test di sini mulai hijau setelah constraint-nya dibuang, ia tidak lagi
 * membuktikan apa pun — jadi constraint-lah yang dijaga di sini, bukan perilaku kodenya.
 */
class EnvironmentRegistryTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Client::create(['legal_name' => 'PT Uji', 'slug' => 'pt-uji', 'status' => 'active']);
        $this->tenantId = Tenant::create([
            'client_id' => $client->id,
            'name' => 'PT Uji',
            'slug' => 'pt-uji',
            'status' => 'active',
        ])->id;
    }

    public function test_tenant_tidak_dapat_punya_dua_produksi(): void
    {
        $this->buat(['kind' => 'production', 'slug' => 'pertama']);

        $this->expectException(QueryException::class);
        $this->buat(['kind' => 'production', 'slug' => 'kedua']);
    }

    public function test_produksi_kedua_boleh_ada_setelah_yang_pertama_dihapus_lunak(): void
    {
        $pertama = $this->buat(['kind' => 'production', 'slug' => 'pertama']);

        DB::table('environments')->where('id', $pertama->id)->update([
            'status' => 'soft_deleted',
            'deleted_at' => now(),
            'purge_after' => now()->addDays(30),
        ]);

        $kedua = $this->buat(['kind' => 'production', 'slug' => 'kedua']);

        $this->assertSame('production', $kedua->kind);
    }

    public function test_sandbox_tidak_boleh_menyalakan_sambungan_keluar(): void
    {
        $this->expectException(QueryException::class);
        $this->buat(['kind' => 'sandbox', 'outbound_allowed' => true]);
    }

    public function test_produksi_tidak_boleh_mematikan_sambungan_keluar(): void
    {
        $this->expectException(QueryException::class);
        $this->buat(['kind' => 'production', 'outbound_allowed' => false]);
    }

    public function test_demo_tanpa_tanggal_berakhir_ditolak(): void
    {
        $this->expectException(QueryException::class);
        $this->buat(['kind' => 'demo', 'outbound_allowed' => false, 'expires_at' => null]);
    }

    public function test_demo_dengan_tanggal_berakhir_diterima(): void
    {
        $demo = $this->buat([
            'kind' => 'demo',
            'outbound_allowed' => false,
            'expires_at' => now()->addDays(30),
        ]);

        $this->assertSame('demo', $demo->kind);
    }

    public function test_hanya_sandbox_yang_boleh_punya_environment_sumber(): void
    {
        $produksi = $this->buat(['kind' => 'production']);

        $this->expectException(QueryException::class);
        $this->buat([
            'kind' => 'demo',
            'outbound_allowed' => false,
            'expires_at' => now()->addDays(7),
            'source_environment_id' => $produksi->id,
        ]);
    }

    public function test_hapus_lunak_tanpa_jadwal_hilang_ditolak(): void
    {
        $environment = $this->buat(['kind' => 'production']);

        $this->expectException(QueryException::class);
        DB::table('environments')->where('id', $environment->id)->update([
            'status' => 'soft_deleted',
            'deleted_at' => now(),
        ]);
    }

    public function test_hanya_satu_operasi_boleh_berjalan_per_environment(): void
    {
        $environment = $this->buat(['kind' => 'production']);
        $this->operasi($environment->id, 'copy');

        $this->expectException(QueryException::class);
        $this->operasi($environment->id, 'migrate');
    }

    public function test_operasi_boleh_berjalan_lagi_setelah_yang_sebelumnya_selesai(): void
    {
        $environment = $this->buat(['kind' => 'production']);
        $pertama = $this->operasi($environment->id, 'copy');

        DB::table('environment_operations')->where('id', $pertama)->update([
            'status' => 'succeeded',
            'finished_at' => now(),
        ]);

        $this->operasi($environment->id, 'migrate');

        $this->assertDatabaseCount('environment_operations', 2);
    }

    public function test_operasi_gagal_wajib_menyebut_alasannya(): void
    {
        $environment = $this->buat(['kind' => 'production']);
        $id = $this->operasi($environment->id, 'copy');

        $this->expectException(QueryException::class);
        DB::table('environment_operations')->where('id', $id)->update([
            'status' => 'failed',
            'finished_at' => now(),
            'failure_message' => null,
        ]);
    }

    /** @param  array<string, mixed>  $atribut */
    private function buat(array $atribut): Environment
    {
        return Environment::create(array_merge([
            'tenant_id' => $this->tenantId,
            'kind' => 'production',
            'name' => 'Uji',
            'slug' => 'uji-'.Str::lower(Str::random(6)),
            'database_name' => null,
            'status' => 'active',
            'outbound_allowed' => true,
        ], $atribut));
    }

    private function operasi(string $environmentId, string $jenis): string
    {
        $id = (string) Str::ulid();

        DB::table('environment_operations')->insert([
            'id' => $id,
            'environment_id' => $environmentId,
            'operation' => $jenis,
            'status' => 'running',
            'started_at' => now(),
            // Wajib sejak constraint `environment_operations_berjalan_bertenggat`: operasi berjalan
            // harus membawa tenggatnya. Kunci yang dipegang selamanya bukan kunci melainkan
            // kebuntuan — proses yang mati keras akan menolak percobaan ulang yang merupakan
            // satu-satunya pemulihan yang desain ini izinkan.
            'lease_until' => now()->addMinutes(30),
        ]);

        return $id;
    }
}
