<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithCoreErpContext;
use Tests\TestCase;

class ModelAsetTest extends TestCase
{
    use InteractsWithCoreErpContext, RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::ulid();
        $this->configureCoreErpContext();
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
            ->postJson('/api/v1/model-aset', ['nama' => 'PC200-8', 'keterangan' => 'Excavator 20 ton', 'pabrikan_aset_id' => $this->pabrikan()])
            ->assertCreated()
            ->assertJsonPath('data.kode', 'MDLA-000001');

        $id = $created->json('data.id');
        Http::assertSent(fn ($request) => $request->hasHeader('X-CoreERP-Tenant-Id', $this->tenantId)
            && $request['idempotency_key'] === 'model-aset:create-model-1');

        $this->withContext(['management-aset.model-aset.update'])
            ->patchJson('/api/v1/model-aset/'.$id, ['nama' => 'PC200-8 MK2', 'aktif' => false])
            ->assertOk()
            ->assertJsonPath('data.aktif', false);

        $this->withContext(['management-aset.model-aset.archive'])
            ->deleteJson('/api/v1/model-aset/'.$id)
            ->assertNoContent();

        $this->assertSoftDeleted('aset_m_model_aset', ['id' => $id, 'tenant_id' => $this->tenantId]);
    }

    public function test_retry_is_idempotent_and_tenants_cannot_read_each_others_records(): void
    {
        Http::fake(['core.test/*' => Http::response(['data' => ['number' => 'MDLA-000001']], 200)]);
        $pabrikan = $this->pabrikan();

        $first = $this->withContext(['management-aset.model-aset.create'])
            ->withHeader('Idempotency-Key', 'same-request')
            ->postJson('/api/v1/model-aset', ['nama' => 'Pertama', 'pabrikan_aset_id' => $pabrikan])
            ->assertCreated();
        $this->withContext(['management-aset.model-aset.create'])
            ->withHeader('Idempotency-Key', 'same-request')
            ->postJson('/api/v1/model-aset', ['nama' => 'Pertama', 'pabrikan_aset_id' => $pabrikan])
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));
        $this->withContext(['management-aset.model-aset.create'])
            ->withHeader('Idempotency-Key', 'same-request')
            ->postJson('/api/v1/model-aset', ['nama' => 'Data berbeda', 'pabrikan_aset_id' => $pabrikan])
            ->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_conflict');
        Http::assertSentCount(1);

        $otherTenant = (string) Str::ulid();
        $this->withHeaders($this->contextHeaders($otherTenant, ['management-aset.model-aset.read']))
            ->getJson('/api/v1/model-aset/'.$first->json('data.id'))
            ->assertNotFound();
    }

    public function test_pabrikan_detail_menghitung_model_dan_aset_dengan_izin_masing_masing(): void
    {
        $pabrikan = $this->pabrikan();
        $model = $this->model($pabrikan);
        $this->asset($pabrikan, $model, (string) Str::ulid(), (string) Str::ulid());

        $permissions = [
            'management-aset.pabrikan-aset.read',
            'management-aset.model-aset.read',
            'management-aset.aset.read',
        ];

        $this->withHeaders($this->contextHeaders($this->tenantId, $permissions, [
            'data_policies' => ['management-aset.asset-responsibility' => ['all' => true, 'scope_grants' => []]],
        ]))
            ->getJson('/api/v1/pabrikan-aset/'.$pabrikan.'/detail')
            ->assertOk()
            ->assertJsonPath('data.model_count', 1)
            ->assertJsonPath('data.asset_count', 1);

        $this->withHeaders($this->contextHeaders($this->tenantId, $permissions, [
            'data_policies' => ['management-aset.asset-responsibility' => ['all' => true, 'scope_grants' => []]],
        ]))
            ->getJson('/api/v1/model-aset?pabrikan_aset_id='.$pabrikan)
            ->assertOk()
            ->assertJsonPath('data.0.asset_count', 1);
    }

    public function test_pabrikan_detail_menyembunyikan_angka_yang_tidak_boleh_dibaca(): void
    {
        $pabrikan = $this->pabrikan();
        $model = $this->model($pabrikan);
        $this->asset($pabrikan, $model, (string) Str::ulid(), (string) Str::ulid());

        $this->withHeaders($this->contextHeaders($this->tenantId, ['management-aset.pabrikan-aset.read']))
            ->getJson('/api/v1/pabrikan-aset/'.$pabrikan.'/detail')
            ->assertOk()
            ->assertJsonPath('data.model_count', null)
            ->assertJsonPath('data.asset_count', null);
    }

    public function test_pabrikan_detail_dan_daftar_model_menghormati_scope_dan_arsip(): void
    {
        $pabrikan = $this->pabrikan();
        $model = $this->model($pabrikan);
        $legalEntity = (string) Str::ulid();
        $operatingUnit = (string) Str::ulid();
        $asset = $this->asset($pabrikan, $model, $legalEntity, $operatingUnit);
        $permissions = [
            'management-aset.pabrikan-aset.read',
            'management-aset.model-aset.read',
            'management-aset.aset.read',
        ];
        $outsideScope = [
            'data_policies' => ['management-aset.asset-responsibility' => [
                'all' => false,
                'scope_grants' => [['legal_entity_id' => (string) Str::ulid(), 'operating_unit_ids' => [(string) Str::ulid()]]],
            ]],
        ];

        $this->withHeaders($this->contextHeaders($this->tenantId, $permissions, $outsideScope))
            ->getJson('/api/v1/pabrikan-aset/'.$pabrikan.'/detail')
            ->assertOk()
            ->assertJsonPath('data.model_count', 1)
            ->assertJsonPath('data.asset_count', 0);

        $this->withHeaders($this->contextHeaders($this->tenantId, $permissions, $outsideScope))
            ->getJson('/api/v1/model-aset?pabrikan_aset_id='.$pabrikan)
            ->assertOk()
            ->assertJsonPath('data.0.asset_count', 0);

        DB::table('aset_tr_penerimaan_aset')->where('id', $asset)->update(['deleted_at' => now()]);
        $this->withHeaders($this->contextHeaders($this->tenantId, $permissions, [
            'data_policies' => ['management-aset.asset-responsibility' => ['all' => true, 'scope_grants' => []]],
        ]))
            ->getJson('/api/v1/pabrikan-aset/'.$pabrikan.'/detail')
            ->assertOk()
            ->assertJsonPath('data.asset_count', 0);
    }

    public function test_jumlah_aset_hanya_menghitung_aset_yang_masih_aktif(): void
    {
        $pabrikan = $this->pabrikan();
        $model = $this->model($pabrikan);
        $active = $this->asset($pabrikan, $model, (string) Str::ulid(), (string) Str::ulid());
        $decommissioned = $this->asset($pabrikan, $model, (string) Str::ulid(), (string) Str::ulid());
        $disposed = $this->asset($pabrikan, $model, (string) Str::ulid(), (string) Str::ulid());
        DB::table('aset_tr_penerimaan_aset')->where('id', $decommissioned)->update(['lifecycle_state' => 'decommissioned']);
        DB::table('aset_tr_penerimaan_aset')->where('id', $disposed)->update(['lifecycle_state' => 'disposed']);

        $permissions = [
            'management-aset.pabrikan-aset.read',
            'management-aset.model-aset.read',
            'management-aset.aset.read',
        ];
        $headers = $this->contextHeaders($this->tenantId, $permissions, [
            'data_policies' => ['management-aset.asset-responsibility' => ['all' => true, 'scope_grants' => []]],
        ]);

        $this->withHeaders($headers)
            ->getJson('/api/v1/pabrikan-aset/'.$pabrikan.'/detail')
            ->assertOk()
            ->assertJsonPath('data.asset_count', 1);

        $this->withHeaders($headers)
            ->getJson('/api/v1/model-aset?pabrikan_aset_id='.$pabrikan)
            ->assertOk()
            ->assertJsonPath('data.0.asset_count', 1);

        $this->assertDatabaseHas('aset_tr_penerimaan_aset', ['id' => $active, 'lifecycle_state' => 'received']);
    }

    public function test_model_tidak_dapat_diarsipkan_saat_masih_dipakai_aset(): void
    {
        $pabrikan = $this->pabrikan();
        $model = $this->model($pabrikan);
        $asset = $this->asset($pabrikan, $model, (string) Str::ulid(), (string) Str::ulid());

        $this->withContext(['management-aset.model-aset.archive'])
            ->deleteJson('/api/v1/model-aset/'.$model)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'referenced_by_children');

        DB::table('aset_tr_penerimaan_aset')->where('id', $asset)->update(['deleted_at' => now()]);

        $this->withContext(['management-aset.model-aset.archive'])
            ->deleteJson('/api/v1/model-aset/'.$model)
            ->assertNoContent();
    }

    public function test_pabrikan_detail_tidak_membuka_data_tenant_lain(): void
    {
        $pabrikan = $this->pabrikan();

        $this->withHeaders($this->contextHeaders((string) Str::ulid(), ['management-aset.pabrikan-aset.read']))
            ->getJson('/api/v1/pabrikan-aset/'.$pabrikan.'/detail')
            ->assertNotFound();
    }

    public function test_gateway_context_and_permission_are_required(): void
    {
        $this->getJson('/api/v1/model-aset')->assertUnauthorized();
        $this->withContext([])->getJson('/api/v1/model-aset')->assertForbidden();
        $token = $this->contextToken($this->tenantId, ['management-aset.model-aset.read']);
        $tampered = substr($token, 0, -1).($token[-1] === 'a' ? 'b' : 'a');
        $this->withHeader('Authorization', 'Bearer '.$tampered)
            ->getJson('/api/v1/model-aset')
            ->assertUnauthorized();
    }

    /** @param list<string> $permissions */
    private function withContext(array $permissions): static
    {
        return $this->withHeaders($this->contextHeaders($this->tenantId, $permissions));
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

    private function asset(string $pabrikanId, string $modelId, string $legalEntityId, string $operatingUnitId): string
    {
        $group = $this->reference('aset_m_group_aset', 'group');
        $jenis = $this->reference('aset_m_jenis_aset', 'jenis');
        $id = (string) Str::ulid();
        DB::table('aset_tr_penerimaan_aset')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'creation_key' => 'asset-'.Str::ulid(),
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
