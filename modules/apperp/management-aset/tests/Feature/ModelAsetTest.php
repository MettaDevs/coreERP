<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Tests\TestCase;

class ModelAsetTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
    }

    public function test_crud_uses_core_number_sequence_and_archives_instead_of_deleting(): void
    {
        Http::fake(['core.test/*' => Http::response(['data' => ['number' => 'MDLA-000001']], 200)]);

        $created = $this->withContext([
            'management-aset.model-aset.read',
            'management-aset.model-aset.create',
            'management-aset.model-aset.update',
            'management-aset.model-aset.archive',
        ])->withHeader('Idempotency-Key', 'create-model-1')
            ->postJson('/api/modules/management-aset/v1/model-aset', ['nama' => 'PC200-8', 'keterangan' => 'Excavator 20 ton', 'pabrikan_aset_id' => $this->pabrikan()])
            ->assertCreated()
            ->assertJsonPath('data.kode', 'MDLA-000001');

        $id = $created->json('data.id');
        // Kunci idempoten dulu diperiksa pada header permintaan HTTP; sekarang ia tersimpan
        // pada baris penerbitan Core, yang membuktikan lebih banyak — kunci yang benar terkirim
        // **dan** dipakai untuk mencatat penerbitannya.
        $this->assertDatabaseHas('number_sequence_issues', ['idempotency_key' => 'model-aset:create-model-1']);

        $this->withContext(['management-aset.model-aset.update'])
            ->patchJson('/api/modules/management-aset/v1/model-aset/'.$id, ['nama' => 'PC200-8 MK2', 'aktif' => false])
            ->assertOk()
            ->assertJsonPath('data.aktif', false);

        $this->withContext(['management-aset.model-aset.archive'])
            ->deleteJson('/api/modules/management-aset/v1/model-aset/'.$id)
            ->assertNoContent();

        $this->assertSoftDeleted('aset_m_model_aset', ['id' => $id, 'tenant_id' => $this->tenantId]);
    }

    public function test_retry_is_idempotent_and_tenants_cannot_read_each_others_records(): void
    {
        Http::fake(['core.test/*' => Http::response(['data' => ['number' => 'MDLA-000001']], 200)]);
        $pabrikan = $this->pabrikan();

        $first = $this->withContext(['management-aset.model-aset.create'])
            ->withHeader('Idempotency-Key', 'same-request')
            ->postJson('/api/modules/management-aset/v1/model-aset', ['nama' => 'Pertama', 'pabrikan_aset_id' => $pabrikan])
            ->assertCreated();
        $this->withContext(['management-aset.model-aset.create'])
            ->withHeader('Idempotency-Key', 'same-request')
            ->postJson('/api/modules/management-aset/v1/model-aset', ['nama' => 'Pertama', 'pabrikan_aset_id' => $pabrikan])
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));
        $this->withContext(['management-aset.model-aset.create'])
            ->withHeader('Idempotency-Key', 'same-request')
            ->postJson('/api/modules/management-aset/v1/model-aset', ['nama' => 'Data berbeda', 'pabrikan_aset_id' => $pabrikan])
            ->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_conflict');
        $this->assertSame(1, $this->jumlahNomorTerbit(), 'Jumlah nomor yang benar-benar diterbitkan Core tidak sesuai.');

        $otherTenant = (string) Str::ulid();
        $this->sebagaiPengguna($otherTenant, ['management-aset.model-aset.read'])
            ->getJson('/api/modules/management-aset/v1/model-aset/'.$first->json('data.id'))
            ->assertNotFound();
    }

    public function test_pabrikan_detail_menghitung_model_dan_aset_dengan_izin_masing_masing(): void
    {
        $pabrikan = $this->pabrikan();
        $model = $this->model($pabrikan);
        $this->aset($pabrikan, $model, (string) Str::ulid(), (string) Str::ulid());

        $permissions = [
            'management-aset.pabrikan-aset.read',
            'management-aset.model-aset.read',
            'management-aset.aset.read',
        ];

        $this->sebagaiPengguna($this->tenantId, $permissions)
            ->getJson('/api/modules/management-aset/v1/pabrikan-aset/'.$pabrikan.'/detail')
            ->assertOk()
            ->assertJsonPath('data.model_count', 1)
            ->assertJsonPath('data.aset_count', 1);

        $this->sebagaiPengguna($this->tenantId, $permissions)
            ->getJson('/api/modules/management-aset/v1/model-aset?pabrikan_aset_id='.$pabrikan)
            ->assertOk()
            ->assertJsonPath('data.0.aset_count', 1);
    }

    public function test_pabrikan_detail_menyembunyikan_angka_yang_tidak_boleh_dibaca(): void
    {
        $pabrikan = $this->pabrikan();
        $model = $this->model($pabrikan);
        $this->aset($pabrikan, $model, (string) Str::ulid(), (string) Str::ulid());

        $this->sebagaiPengguna($this->tenantId, ['management-aset.pabrikan-aset.read'])
            ->getJson('/api/modules/management-aset/v1/pabrikan-aset/'.$pabrikan.'/detail')
            ->assertOk()
            ->assertJsonPath('data.model_count', null)
            ->assertJsonPath('data.aset_count', null);
    }

    public function test_pabrikan_detail_dan_daftar_model_menghormati_scope_dan_arsip(): void
    {
        $pabrikan = $this->pabrikan();
        $model = $this->model($pabrikan);
        $legalEntity = (string) Str::ulid();
        $operatingUnit = (string) Str::ulid();
        $aset = $this->aset($pabrikan, $model, $legalEntity, $operatingUnit);
        $permissions = [
            'management-aset.pabrikan-aset.read',
            'management-aset.model-aset.read',
            'management-aset.aset.read',
        ];
        $outsideScope = [[
            'policy_code' => 'management-aset.asset-responsibility',
            'legal_entity_id' => (string) Str::ulid(),
            'organization_id' => (string) Str::ulid(),
        ]];

        $this->sebagaiPengguna($this->tenantId, $permissions, $outsideScope)
            ->getJson('/api/modules/management-aset/v1/pabrikan-aset/'.$pabrikan.'/detail')
            ->assertOk()
            ->assertJsonPath('data.model_count', 1)
            ->assertJsonPath('data.aset_count', 0);

        $this->sebagaiPengguna($this->tenantId, $permissions, $outsideScope)
            ->getJson('/api/modules/management-aset/v1/model-aset?pabrikan_aset_id='.$pabrikan)
            ->assertOk()
            ->assertJsonPath('data.0.aset_count', 0);

        DB::table('aset_tr_aset')->where('id', $aset)->update(['deleted_at' => now()]);
        $this->sebagaiPengguna($this->tenantId, $permissions)
            ->getJson('/api/modules/management-aset/v1/pabrikan-aset/'.$pabrikan.'/detail')
            ->assertOk()
            ->assertJsonPath('data.aset_count', 0);
    }

    public function test_jumlah_aset_hanya_menghitung_aset_yang_masih_aktif(): void
    {
        $pabrikan = $this->pabrikan();
        $model = $this->model($pabrikan);
        $active = $this->aset($pabrikan, $model, (string) Str::ulid(), (string) Str::ulid());
        $decommissioned = $this->aset($pabrikan, $model, (string) Str::ulid(), (string) Str::ulid());
        $disposed = $this->aset($pabrikan, $model, (string) Str::ulid(), (string) Str::ulid());
        DB::table('aset_tr_aset')->where('id', $decommissioned)->update(['lifecycle_state' => 'decommissioned']);
        DB::table('aset_tr_aset')->where('id', $disposed)->update(['lifecycle_state' => 'disposed']);

        $permissions = [
            'management-aset.pabrikan-aset.read',
            'management-aset.model-aset.read',
            'management-aset.aset.read',
        ];
        $this->sebagaiPengguna($this->tenantId, $permissions);

        $this
            ->getJson('/api/modules/management-aset/v1/pabrikan-aset/'.$pabrikan.'/detail')
            ->assertOk()
            ->assertJsonPath('data.aset_count', 1);

        $this
            ->getJson('/api/modules/management-aset/v1/model-aset?pabrikan_aset_id='.$pabrikan)
            ->assertOk()
            ->assertJsonPath('data.0.aset_count', 1);

        $this->assertDatabaseHas('aset_tr_aset', ['id' => $active, 'lifecycle_state' => 'received']);
    }

    public function test_model_tidak_dapat_diarsipkan_saat_masih_dipakai_aset(): void
    {
        $pabrikan = $this->pabrikan();
        $model = $this->model($pabrikan);
        $aset = $this->aset($pabrikan, $model, (string) Str::ulid(), (string) Str::ulid());

        $this->withContext(['management-aset.model-aset.archive'])
            ->deleteJson('/api/modules/management-aset/v1/model-aset/'.$model)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'referenced_by_children');

        DB::table('aset_tr_aset')->where('id', $aset)->update(['deleted_at' => now()]);

        $this->withContext(['management-aset.model-aset.archive'])
            ->deleteJson('/api/modules/management-aset/v1/model-aset/'.$model)
            ->assertNoContent();
    }

    public function test_pabrikan_detail_tidak_membuka_data_tenant_lain(): void
    {
        $pabrikan = $this->pabrikan();

        $this->sebagaiPengguna((string) Str::ulid(), ['management-aset.pabrikan-aset.read'])
            ->getJson('/api/modules/management-aset/v1/pabrikan-aset/'.$pabrikan.'/detail')
            ->assertNotFound();
    }

    /**
     * Dua penolakan yang berbeda, dan bedanya penting.
     *
     * Tanpa pengguna sama sekali: 401, dijawab `auth`. Itu soal identitas.
     * Dengan pengguna tetapi tanpa satu pun izin atas module ini: 403, dijawab middleware
     * konteks module. Itu soal wewenang.
     *
     * Bagian ketiga test lama — token yang dirusak satu huruf — sengaja dibuang, bukan
     * diterjemahkan. Tidak ada lagi token untuk dirusak: module berjalan di proses yang sama
     * dan membaca sesi Core. Test yang menguji mekanisme yang sudah tidak ada akan tetap
     * hijau selamanya tanpa menjaga apa pun.
     */
    public function test_tanpa_pengguna_ditolak_401_dan_tanpa_izin_ditolak_403(): void
    {
        $this->getJson('/api/modules/management-aset/v1/model-aset')->assertUnauthorized();

        $this->withContext([])->getJson('/api/modules/management-aset/v1/model-aset')->assertForbidden();
    }

    /** @param list<string> $permissions */
    private function withContext(array $permissions): static
    {
        return $this->sebagaiPengguna($this->tenantId, $permissions);
    }

    /** Induk wajib model aset; datar, jadi cukup satu insert tanpa rantai apa pun. */
    private function pabrikan(): string
    {
        $now = now();
        $id = (string) Str::ulid();
        DB::table('aset_m_pabrikan_aset')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'creation_key' => 'pabrikan-'.Str::ulid(),
            'kode' => 'P'.Str::random(6),
            'nama' => 'Pabrikan',
            'aktif' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    private function model(string $pabrikanId): string
    {
        $id = (string) Str::ulid();
        DB::table('aset_m_model_aset')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'creation_key' => 'model-'.Str::ulid(),
            'pabrikan_aset_id' => $pabrikanId,
            'jenis_aset_id' => null,
            'kode' => 'MDL'.Str::random(6),
            'nama' => 'Model uji',
            'aktif' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function aset(string $pabrikanId, string $modelId, string $legalEntityId, string $operatingUnitId): string
    {
        $group = $this->reference('aset_m_group_aset', 'group');
        $jenis = $this->reference('aset_m_jenis_aset', 'jenis');
        $id = (string) Str::ulid();
        DB::table('aset_tr_aset')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'creation_key' => 'aset-'.Str::ulid(),
            'kode' => 'AST'.Str::random(6),
            'nama' => 'Aset model uji',
            'legal_entity_id' => $legalEntityId,
            'responsible_org_unit_id' => $operatingUnitId,
            'group_aset_id' => $group,
            'jenis_aset_id' => $jenis,
            'pabrikan_aset_id' => $pabrikanId,
            'model_aset_id' => $modelId,
            'acquired_on' => '2026-08-14',
            'acquisition_value' => 1000,
            'currency_code' => 'IDR',
            'lifecycle_state' => 'received',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function reference(string $table, string $prefix): string
    {
        $id = (string) Str::ulid();
        DB::table($table)->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'creation_key' => $prefix.'-'.Str::ulid(),
            'kode' => strtoupper(substr($prefix, 0, 3)).Str::random(6),
            'nama' => 'Referensi uji',
            'aktif' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
