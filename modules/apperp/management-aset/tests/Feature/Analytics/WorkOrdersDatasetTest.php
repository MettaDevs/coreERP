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
 * Dataset `management-aset.work-orders` terhadap layar daftar work order (`GET pemeliharaan-aset`):
 * kebijakan data pada unit penanggung jawab milik header work order sendiri.
 */
class WorkOrdersDatasetTest extends TestCase
{
    use ChecksTimeZoneBuckets, ProbesAssetDatasets, RefreshDatabase;

    protected function datasetCode(): string
    {
        return 'management-aset.work-orders';
    }

    protected function readPermission(): string
    {
        return 'management-aset.pemeliharaan-aset.read';
    }

    protected function listResource(): string
    {
        return 'pemeliharaan-aset';
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
        $type = $this->master($tenant, 'aset_m_tipe_work_order', "TWO-{$tag}");

        $this->workOrder($tenant, $legalEntity, "{$tag}-WO1", $unitA, $type, 'draft');
        $this->workOrder($tenant, $legalEntity, "{$tag}-WO2", $unitA, $type, 'selesai');
        $this->workOrder($tenant, $legalEntity, "{$tag}-WO3", $unitB, $type, 'ditutup');
        $this->workOrder($tenant, $legalEntity, "{$tag}-WO4", $unitB, $type, 'dibatalkan');
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
        $type = (string) DB::table('aset_m_tipe_work_order')->where('tenant_id', $this->tenantId)->value('id');
        $this->workOrder($this->tenantId, $this->legalEntity, 'BATAS', $this->unitA, $type, 'draft', $value);

        return ['kode' => 'BATAS'];
    }

    public function test_finished_closed_and_cancelled_work_orders_are_told_apart(): void
    {
        // Selesai dan ditutup sama-sama pekerjaan yang beres; dibatalkan dihitung sendiri.
        $this->analyze($this->owner, ['dataset' => $this->datasetCode(), 'measures' => ['count', 'completed', 'cancelled']])
            ->assertOk()
            ->assertJsonPath('rows.0.count', 4)
            ->assertJsonPath('rows.0.completed', 2)
            ->assertJsonPath('rows.0.cancelled', 1);
    }

    public function test_work_orders_are_counted_per_status_and_can_be_selected_by_status(): void
    {
        $rows = $this->analyze($this->owner, [
            'dataset' => $this->datasetCode(), 'dimensions' => ['status'], 'measures' => ['count'],
        ])->assertOk()->json('rows');
        $perStatus = array_column($rows, 'count', 'status');
        ksort($perStatus);

        $this->assertSame(['dibatalkan' => 1, 'ditutup' => 1, 'draft' => 1, 'selesai' => 1], $perStatus);

        $this->analyze($this->owner, [
            'dataset' => $this->datasetCode(), 'measures' => ['count'], 'filters' => ['status' => ['selesai', 'ditutup']],
        ])->assertOk()->assertJsonPath('rows.0.count', 2);
    }

    private function workOrder(string $tenant, string $legalEntity, string $code, string $unit, string $typeId, string $status, ?string $createdAt = null): void
    {
        DB::table('aset_tr_pemeliharaan_aset')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'creation_key' => 'seed-'.Str::ulid(), 'kode' => $code,
            'legal_entity_id' => $legalEntity, 'responsible_org_unit_id' => $unit, 'tipe_work_order_id' => $typeId,
            'status' => $status, 'created_at' => $createdAt ?? now(), 'updated_at' => now(),
        ]);
    }
}
