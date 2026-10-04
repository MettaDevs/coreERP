<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Tests\Concerns\ChecksTimeZoneBuckets;
use Modules\Apperp\ManagementAset\Tests\Concerns\ProbesAssetDatasets;
use Tests\TestCase;

/**
 * Dataset `management-aset.maintenance-requests` terhadap layar daftar permintaan pemeliharaan
 * (`GET permintaan-pemeliharaan`): kebijakan data pada unit pelapor milik permintaan itu sendiri, bukan
 * unit asetnya — permintaan atas lokasi tidak punya aset sama sekali.
 */
class MaintenanceRequestsDatasetTest extends TestCase
{
    use ChecksTimeZoneBuckets, ProbesAssetDatasets, RefreshDatabase;

    protected function datasetCode(): string
    {
        return 'management-aset.maintenance-requests';
    }

    protected function readPermission(): string
    {
        return 'management-aset.permintaan-pemeliharaan.read';
    }

    protected function listResource(): string
    {
        return 'permintaan-pemeliharaan';
    }

    protected function dimensions(): array
    {
        return ['kode'];
    }

    protected function expectedRows(): array
    {
        return ['all' => 4, 'unitA' => 2, 'unitB' => 2];
    }

    protected function seedRows(string $tenant, string $legalEntity, string $unitA, string $unitB, string $tag): void
    {
        $type = $this->master($tenant, 'aset_m_jenis_permintaan_pemeliharaan', "JNP-{$tag}");
        // Aset milik unit B dilaporkan oleh unit A: kebijakan yang mengikuti aset melihatnya di unit B.
        $assetOfB = $this->asset($tenant, $legalEntity, "{$tag}-ASET-B", $unitB);
        $assetOfA = $this->asset($tenant, $legalEntity, "{$tag}-ASET-A", $unitA);
        $location = $this->master($tenant, 'aset_m_lokasi_aset', "LOK-{$tag}");

        $this->request($tenant, $legalEntity, "{$tag}-MR1", $unitA, $type, $assetOfB);
        $this->request($tenant, $legalEntity, "{$tag}-MR2", $unitA, $type, $assetOfB, null, 'ditolak');
        $this->request($tenant, $legalEntity, "{$tag}-MR3", $unitB, $type, $assetOfA, null, 'diterima');
        // Permintaan atas lokasi: sasarannya aset atau lokasi, dan yang ini tidak menyebut aset.
        $this->request($tenant, $legalEntity, "{$tag}-MR4", $unitB, $type, null, $location);
    }

    protected function timeField(): string
    {
        return 'created_at';
    }

    protected function timeKind(): string
    {
        return 'timestamp';
    }

    /** @return array<string, string|list<string>> */
    protected function insertBoundaryRow(string $value): array
    {
        $type = (string) DB::table('aset_m_jenis_permintaan_pemeliharaan')->where('tenant_id', $this->tenantId)->value('id');
        $this->request($this->tenantId, $this->legalEntity, 'BATAS', $this->unitA, $type, $this->assetIds['A-ASET-A'], null, 'draft', $value);

        return ['kode' => 'BATAS'];
    }

    public function test_accepted_and_rejected_requests_are_counted_by_their_decision(): void
    {
        $this->analyze($this->owner, ['dataset' => $this->datasetCode(), 'measures' => ['count', 'accepted', 'rejected']])
            ->assertOk()
            ->assertJsonPath('rows.0.count', 4)
            ->assertJsonPath('rows.0.accepted', 1)
            ->assertJsonPath('rows.0.rejected', 1);
    }

    public function test_the_same_asset_reported_twice_is_one_asset_but_two_requests(): void
    {
        $this->analyze($this->owner, ['dataset' => $this->datasetCode(), 'measures' => ['count', 'asset_count']])
            ->assertOk()
            ->assertJsonPath('rows.0.count', 4)
            // Dua permintaan atas aset yang sama, satu atas aset lain, satu tanpa aset (tidak terhitung).
            ->assertJsonPath('rows.0.asset_count', 2);
    }

    private function request(string $tenant, string $legalEntity, string $code, string $unit, string $typeId, ?string $assetId, ?string $locationId = null, string $status = 'draft', ?string $createdAt = null): void
    {
        DB::table('aset_tr_permintaan_pemeliharaan')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'creation_key' => 'seed-'.Str::ulid(), 'kode' => $code,
            'legal_entity_id' => $legalEntity, 'responsible_org_unit_id' => $unit, 'jenis_permintaan_id' => $typeId,
            'aset_id' => $assetId, 'lokasi_aset_id' => $locationId, 'deskripsi' => 'Perlu diperiksa', 'status' => $status,
            'created_at' => $createdAt ?? now(), 'updated_at' => now(),
        ]);
    }
}
