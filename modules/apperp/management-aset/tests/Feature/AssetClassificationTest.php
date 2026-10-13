<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Tests\TestCase;

class AssetClassificationTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    public function test_permissions_are_separate_and_rows_are_tenant_scoped(): void
    {
        $tenantId = $this->buatTenantUji();
        $ownId = $this->insertClassification($tenantId);
        $otherId = $this->insertClassification($this->buatTenantUji());
        $path = '/api/modules/management-aset/v1/kelompok-harta';
        $this->sebagaiPengguna($tenantId, ['management-aset.group-aset.read', 'management-aset.group-aset.update'])
            ->getJson($path)->assertForbidden();
        $this->patchJson($path.'/'.$ownId, ['label' => 'Ditolak', 'version' => 1])->assertForbidden();
        $this->sebagaiPengguna($tenantId, ['management-aset.kelompok-harta.read'])
            ->getJson($path)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ownId);
        $this->patchJson($path.'/'.$ownId, ['label' => 'Ditolak', 'version' => 1])->assertForbidden();
        $this->sebagaiPengguna($tenantId, ['management-aset.kelompok-harta.read', 'management-aset.kelompok-harta.update'])
            ->patchJson($path.'/'.$otherId, ['label' => 'Ditolak', 'version' => 1])->assertNotFound();
        $this->assertDatabaseHas('aset_m_kelompok_harta_fiskal', ['id' => $otherId, 'label' => 'Kelompok uji']);
    }

    public function test_editing_preserves_identity_and_rejects_stale_or_missing_versions(): void
    {
        $tenantId = $this->buatTenantUji();
        $id = $this->insertClassification($tenantId);
        $path = '/api/modules/management-aset/v1/kelompok-harta';
        $this->sebagaiPengguna($tenantId, ['management-aset.kelompok-harta.read', 'management-aset.kelompok-harta.update']);
        $version = $this->getJson($path)->assertOk()->json('data.0.version');
        $this->patchJson($path.'/'.$id, ['label' => 'Kelompok baru'])->assertStatus(428);
        $result = $this->patchJson($path.'/'.$id, [
            'label' => 'Kelompok baru', 'useful_life_years' => 8,
            'straight_line_rate_percent' => 12.5, 'reducing_balance_rate_percent' => 25,
            'version' => $version,
            'tenant_id' => (string) Str::ulid(), 'template_key' => 'Tidak boleh berubah',
        ])->assertOk()->assertJsonPath('data.label', 'Kelompok baru')->assertJsonPath('data.useful_life_years', 8);
        $this->assertGreaterThan($version, $result->json('data.version'));
        $this->patchJson($path.'/'.$id, ['label' => 'Data basi', 'version' => $version])->assertConflict();
        $this->assertDatabaseHas('aset_m_kelompok_harta_fiskal', ['id' => $id, 'tenant_id' => $tenantId, 'template_key' => 'test:classification', 'label' => 'Kelompok baru']);
    }

    public function test_invalid_dates_and_incomplete_rates_roll_back_without_changing_version(): void
    {
        $tenantId = $this->buatTenantUji();
        $id = $this->insertClassification($tenantId);
        $path = '/api/modules/management-aset/v1/kelompok-harta';
        $this->sebagaiPengguna($tenantId, ['management-aset.kelompok-harta.read', 'management-aset.kelompok-harta.update']);
        $version = $this->getJson($path)->json('data.0.version');
        foreach ([
            ['effective_to' => '2020-01-01'],
            ['straight_line_rate_percent' => null],
            ['useful_life_years' => null],
            ['reducing_balance_rate_percent' => null],
            ['straight_line_rate_percent' => 101],
        ] as $payload) {
            $this->patchJson($path.'/'.$id, [...$payload, 'version' => $version])->assertUnprocessable();
            $this->assertDatabaseHas('aset_m_kelompok_harta_fiskal', ['id' => $id, 'version' => $version, 'straight_line_rate_percent' => 25]);
        }
    }

    public function test_create_requires_its_permission_and_is_idempotent_per_tenant(): void
    {
        $tenantId = $this->buatTenantUji();
        $path = '/api/modules/management-aset/v1/kelompok-harta';
        $payload = [
            'label' => 'Kelompok tambahan', 'useful_life_years' => 4,
            'straight_line_rate_percent' => 0, 'reducing_balance_rate_percent' => 0,
            'allow_reducing_balance' => true,
            'tenant_id' => (string) Str::ulid(), 'template_key' => 'Tidak boleh dipakai',
        ];
        $this->sebagaiPengguna($tenantId, ['management-aset.kelompok-harta.update'])
            ->postJson($path, $payload, ['Idempotency-Key' => 'classification-create'])->assertForbidden();
        $this->sebagaiPengguna($tenantId, ['management-aset.kelompok-harta.create']);
        $this->postJson($path, $payload)->assertUnprocessable();
        $created = $this->postJson($path, $payload, ['Idempotency-Key' => 'classification-create'])
            ->assertCreated()->assertJsonPath('data.aktif', true)->assertJsonPath('data.allow_straight_line', true)
            ->assertJsonPath('data.straight_line_rate_percent', '0.0000')
            ->assertJsonPath('data.effective_from', today()->toDateString());
        $id = $created->json('data.id');
        $created->assertHeader('Location', url($path.'/'.$id));
        $this->postJson($path, $payload, ['Idempotency-Key' => 'classification-create'])
            ->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson($path, [...$payload, 'label' => 'Berbeda'], ['Idempotency-Key' => 'classification-create'])->assertConflict();
        $this->assertDatabaseHas('aset_m_kelompok_harta_fiskal', [
            'id' => $id, 'tenant_id' => $tenantId, 'template_key' => 'custom:classification-create',
        ]);
        $otherTenant = $this->buatTenantUji();
        $other = $this->sebagaiPengguna($otherTenant, ['management-aset.kelompok-harta.create'])
            ->postJson($path, $payload, ['Idempotency-Key' => 'classification-create'])->assertCreated();
        $this->assertNotSame($id, $other->json('data.id'));
        $this->assertDatabaseHas('aset_m_kelompok_harta_fiskal', ['id' => $other->json('data.id'), 'tenant_id' => $otherTenant]);
    }

    public function test_zero_rates_and_method_permissions_preserve_dates_and_reject_invalid_life(): void
    {
        $tenantId = $this->buatTenantUji();
        $id = $this->insertClassification($tenantId);
        $path = '/api/modules/management-aset/v1/kelompok-harta/'.$id;
        $this->sebagaiPengguna($tenantId, ['management-aset.kelompok-harta.update']);
        $saved = $this->patchJson($path, [
            'version' => 1, 'straight_line_rate_percent' => 0, 'reducing_balance_rate_percent' => 0,
        ])->assertOk()->assertJsonPath('data.straight_line_rate_percent', '0.0000')
            ->assertJsonPath('data.reducing_balance_rate_percent', '0.0000')
            ->assertJsonPath('data.effective_from', '2023-07-17')->assertJsonPath('data.effective_to', null);
        $version = $saved->json('data.version');
        foreach ([['useful_life_years' => 0], ['straight_line_rate_percent' => -1], ['reducing_balance_rate_percent' => 101]] as $payload) {
            $this->patchJson($path, [...$payload, 'version' => $version])->assertUnprocessable();
            $this->assertDatabaseHas('aset_m_kelompok_harta_fiskal', ['id' => $id, 'version' => $version]);
        }
        $saved = $this->patchJson($path, [
            'version' => $version, 'allow_straight_line' => false, 'straight_line_rate_percent' => null,
            'allow_reducing_balance' => false, 'reducing_balance_rate_percent' => null,
        ])->assertOk()->assertJsonPath('data.allow_straight_line', false);
        $version = $saved->json('data.version');
        $this->patchJson($path, ['version' => $version, 'allow_straight_line' => true])->assertUnprocessable();
        $this->patchJson($path, ['version' => $version, 'allow_reducing_balance' => true])->assertUnprocessable();
    }

    private function insertClassification(string $tenantId): string
    {
        $id = (string) Str::ulid();
        DB::table('aset_m_kelompok_harta_fiskal')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'template_key' => 'test:classification',
            'jurisdiction' => 'ID', 'label' => 'Kelompok uji', 'effective_from' => '2023-07-17',
            'useful_life_years' => 4, 'straight_line_rate_percent' => 25, 'reducing_balance_rate_percent' => 50,
            'allow_reducing_balance' => true, 'depreciable' => true, 'aktif' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
