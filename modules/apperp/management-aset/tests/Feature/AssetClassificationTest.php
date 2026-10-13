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
