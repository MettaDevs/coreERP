<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Tests\TestCase;

class AssetPlanningTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    private string $tenantId;

    private string $legalEntityId;

    private string $orgUnitId;

    private string $unitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        $this->legalEntityId = (string) Str::ulid();
        $this->orgUnitId = (string) Str::ulid();
        $this->unitId = (string) Str::ulid();
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/units-of-measure/resolve')) {
                return Http::response(['data' => [['id' => $this->unitId, 'code' => 'EA', 'name' => 'Unit', 'symbol' => null, 'decimal_places' => 0]]], 200);
            }

            return Http::response(['data' => ['number' => 'PLNA-000001']], 200);
        });
    }

    public function test_planning_uses_asset_type_lookup_and_saves_specification_on_transaction_detail(): void
    {
        $jenis = $this->jenis($this->tenantId);
        $response = $this->create($jenis)->assertCreated()->assertJsonPath('data.kode', 'PLNA-000001');
        $planId = $response->json('data.id');

        $this->assertDatabaseHas('aset_tr_perencanaan_aset', [
            'id' => $planId, 'tenant_id' => $this->tenantId, 'planning_org_unit_id' => $this->orgUnitId,
            'planning_type' => 'regular',
        ]);
        $this->assertDatabaseHas('aset_tr_perencanaan_aset_details', [
            'planning_id' => $planId, 'jenis_aset_id' => $jenis, 'asset_name' => 'Laptop kerja',
            'requested_specification' => 'RAM 16 GB, SSD 512 GB', 'quantity' => 2,
        ]);
        // Dulu memeriksa entitas legal yang dikirim ke Core lewat kabel; sekarang memeriksa
        // nomor yang benar-benar terbit. Satu baris penerbitan berarti penghitungnya maju.
        $this->assertSame(1, $this->jumlahNomorTerbit(), 'Penerbitan nomor tidak terjadi.');

        $this->headers(['management-aset.perencanaan-aset.read'])
            ->getJson('/api/modules/management-aset/v1/perencanaan-aset/'.$planId)
            ->assertOk()->assertJsonPath('data.details.0.jenis_aset_nama', 'Laptop kerja');
    }

    public function test_rejects_asset_type_from_another_tenant_before_issuing_a_number(): void
    {
        $otherTenantType = $this->jenis((string) Str::ulid());
        $this->create($otherTenantType)->assertUnprocessable()->assertJsonValidationErrors('details');
        $this->assertDatabaseCount('aset_tr_perencanaan_aset', 0);
        $this->assertSame(0, $this->jumlahNomorTerbit(), 'Ada nomor yang terbit padahal seharusnya tidak.');
    }

    public function test_update_and_archive_require_their_own_permissions_and_current_version(): void
    {
        $jenis = $this->jenis($this->tenantId);
        $plan = $this->create($jenis)->assertCreated()->json('data');
        $payload = $this->payload($jenis);
        $payload['description'] = 'Kebutuhan diperbarui';
        $payload['version'] = 1;

        $this->headers(['management-aset.perencanaan-aset.read'])
            ->patchJson('/api/modules/management-aset/v1/perencanaan-aset/'.$plan['id'], $payload)->assertForbidden();
        $this->headers(['management-aset.perencanaan-aset.read', 'management-aset.perencanaan-aset.update'])
            ->patchJson('/api/modules/management-aset/v1/perencanaan-aset/'.$plan['id'], $payload)->assertOk()->assertJsonPath('data.version', 2);
        $this->headers(['management-aset.perencanaan-aset.read', 'management-aset.perencanaan-aset.archive'])
            ->deleteJson('/api/modules/management-aset/v1/perencanaan-aset/'.$plan['id'], ['version' => 1])->assertConflict();
        $this->headers(['management-aset.perencanaan-aset.read', 'management-aset.perencanaan-aset.archive'])
            ->deleteJson('/api/modules/management-aset/v1/perencanaan-aset/'.$plan['id'], ['version' => 2])->assertNoContent();
        $this->assertSoftDeleted('aset_tr_perencanaan_aset', ['id' => $plan['id']]);
    }

    private function create(string $jenis)
    {
        return $this->headers(['management-aset.perencanaan-aset.create'])
            ->withHeader('Idempotency-Key', 'plan-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/perencanaan-aset', $this->payload($jenis));
    }

    /** @return array<string, string> */
    private function headers(array $permissions): static
    {
        return $this->sebagaiPengguna($this->tenantId, $permissions);
    }

    /** @return array<string, mixed> */
    private function payload(string $jenis): array
    {
        return [
            'legal_entity_id' => $this->legalEntityId, 'planning_org_unit_id' => $this->orgUnitId,
            'planned_on' => '2026-07-30', 'planning_year' => 2026, 'planning_type' => 'regular',
            'funding_source' => 'Anggaran operasional', 'description' => 'Perangkat tim pengembangan',
            'details' => [[
                'jenis_aset_id' => $jenis, 'satuan_id' => $this->unitId, 'quantity' => 2,
                'requested_specification' => 'RAM 16 GB, SSD 512 GB', 'estimated_unit_price' => 15000000,
            ]],
        ];
    }

    /** Jenis aset kini datar, jadi cukup satu insert tanpa group dan kategori di atasnya. */
    private function jenis(string $tenant): string
    {
        $type = (string) Str::ulid();
        $now = now();
        DB::table('aset_m_jenis_aset')->insert(['id' => $type, 'tenant_id' => $tenant, 'creation_key' => 'type-'.Str::ulid(), 'kode' => 'J'.Str::random(6), 'nama' => 'Laptop kerja', 'aktif' => true, 'created_at' => $now, 'updated_at' => $now]);

        return $type;
    }
}
