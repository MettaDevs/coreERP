<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Models\NumberSequenceReference;
use App\Models\TenantNumberSequence;
use App\Support\Modules\Contracts\DirektoriOrganisasi;
use App\Support\Modules\Contracts\KalenderFiskal;
use App\Support\Modules\Contracts\PenerbitNomor;
use App\Support\Modules\CoreServices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Module memanggil Core lewat satu pintu resmi, dan pintu itu menerima id.
 *
 * Yang diuji di sini bukan logika Core — itu sudah punya testnya sendiri. Yang diuji adalah
 * bahwa pembungkusnya benar-benar meneruskan, dan bahwa antarmukanya cukup untuk dipakai
 * tanpa menyentuh satu pun model Core.
 */
class CoreServicesForModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_semua_antarmuka_terpasang_di_wadah(): void
    {
        foreach (CoreServices::PEMETAAN as $antarmuka => $pelaksana) {
            $this->assertInstanceOf($antarmuka, $this->app->make($antarmuka), $antarmuka.' belum terpasang.');
        }
    }

    public function test_module_menerbitkan_nomor_lewat_antarmuka_tanpa_menyentuh_model_core(): void
    {
        [$konteks] = $this->sequenceContoh();

        $hasil = $this->app->make(PenerbitNomor::class)
            ->terbitkan($konteks, 'sample-app.document', (string) Str::ulid());

        $this->assertSame('issued', $hasil['status']);
        $this->assertSame('000001', $hasil['number']);
    }

    public function test_penerbitan_dengan_kunci_idempoten_yang_sama_tidak_menghasilkan_nomor_kedua(): void
    {
        [$konteks] = $this->sequenceContoh();
        $kunci = (string) Str::ulid();
        $penerbit = $this->app->make(PenerbitNomor::class);

        $pertama = $penerbit->terbitkan($konteks, 'sample-app.document', $kunci);
        $kedua = $penerbit->terbitkan($konteks, 'sample-app.document', $kunci);

        $this->assertSame($pertama['number'], $kedua['number']);
    }

    public function test_pencadangan_nomor_juga_lewat_antarmuka_yang_sama(): void
    {
        // Pencadangan hanya berlaku untuk sequence berurutan tanpa lompatan; yang tidak
        // berurutan memang menolaknya, dan penolakan itu perilaku Core, bukan cacat kontrak.
        [$konteks] = $this->sequenceContoh(berurutan: true);

        $hasil = $this->app->make(PenerbitNomor::class)
            ->cadangkan($konteks, 'sample-app.document', (string) Str::ulid());

        $this->assertArrayHasKey('number', $hasil);
    }

    public function test_kalender_fiskal_menolak_id_entitas_legal_yang_tidak_ada(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak ditemukan');

        $this->app->make(KalenderFiskal::class)->periode((string) Str::ulid(), '2026-09-08');
    }

    public function test_direktori_organisasi_mengembalikan_baris_biasa_bukan_model_core(): void
    {
        [$konteks] = $this->sequenceContoh();

        $unit = $this->app->make(DirektoriOrganisasi::class)->unitOperasi($konteks['tenant_id']);

        $this->assertSame([], $unit);

        DB::table('organizations')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $konteks['tenant_id'],
            'name' => 'Cabang Timur',
            'classification' => 'operating_unit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $unit = $this->app->make(DirektoriOrganisasi::class)->unitOperasi($konteks['tenant_id']);

        $this->assertCount(1, $unit);
        $this->assertSame(['id', 'nama', 'klasifikasi'], array_keys($unit[0]));
        $this->assertIsString($unit[0]['id']);
    }

    /**
     * Satu tenant dengan satu referensi nomor yang siap dipakai.
     *
     * @return array{0: array{tenant_id: string, app_id: string}}
     */
    private function sequenceContoh(bool $berurutan = false): array
    {
        $tenantId = (string) Str::ulid();
        $appId = 'sample-app';
        $clientId = (string) Str::ulid();

        DB::table('clients')->insert(['id' => $clientId, 'legal_name' => 'Klien Contoh', 'slug' => 'klien-'.Str::lower(Str::random(6)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenants')->insert(['id' => $tenantId, 'client_id' => $clientId, 'name' => 'Tenant Contoh', 'slug' => 'tenant-'.Str::lower(Str::random(6)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('apps')->insert(['id' => $appId, 'name' => 'Sample app', 'version' => '1.0.0', 'status' => 'available', 'database_name' => 'sample_app', 'created_at' => now(), 'updated_at' => now()]);

        $reference = NumberSequenceReference::query()->create([
            'app_id' => $appId,
            'code' => 'sample-app.document',
            'name' => 'Nomor dokumen',
            'allowed_scopes' => ['tenant', 'legal_entity', 'operating_unit'],
        ]);

        TenantNumberSequence::query()->create([
            'tenant_id' => $tenantId,
            'reference_id' => $reference->id,
            'profile_code' => $berurutan ? 'continuous-strict' : 'non-continuous-default',
            'scope_type' => 'tenant',
            'status' => 'active',
            'is_continuous' => $berurutan,
            'allow_manual' => false,
            'reset_period' => 'never',
            'preallocation_enabled' => true,
            'preallocation_quantity' => 20,
            'minimum_number' => 1,
            'segments' => [['type' => 'number', 'length' => 6]],
        ]);

        return [['tenant_id' => $tenantId, 'app_id' => $appId]];
    }
}
