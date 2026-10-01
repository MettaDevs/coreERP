<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Master dan aset minimum untuk test counter, rencana, jadwal, dan permintaan pemeliharaan.
 *
 * Disemai langsung ke tabel, seperti test work order: yang diuji di sini fitur pemakainya, bukan
 * layar masternya. Pemakai trait menyediakan `$tenantId`, `$legalEntityId`, dan `$unitId`.
 */
trait SeedsMaintenanceFixtures
{
    /** @param  array<string, mixed>  $extra */
    private function seedMaster(string $table, string $nama, array $extra = []): string
    {
        $id = (string) Str::ulid();
        DB::table($table)->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(),
            'kode' => strtoupper(Str::random(8)), 'nama' => $nama, 'aktif' => true,
            ...$extra,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** @param  array<string, mixed>  $extra */
    private function seedAsset(string $jenisId, string $kode, array $extra = []): string
    {
        $id = (string) Str::ulid();
        DB::table('aset_tr_aset')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(), 'kode' => $kode,
            'nama' => 'Aset '.$kode,
            'legal_entity_id' => $this->legalEntityId, 'responsible_org_unit_id' => $this->unitId,
            'group_aset_id' => $this->groupId(), 'jenis_aset_id' => $jenisId,
            'acquired_on' => '2026-01-01', 'acquisition_value' => 50000000, 'currency_code' => 'IDR',
            ...$extra,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function groupId(): string
    {
        return (string) (DB::table('aset_m_group_aset')->where('tenant_id', $this->tenantId)->value('id')
            ?? $this->seedMaster('aset_m_group_aset', 'Alat kesehatan'));
    }

    private function seedCounterType(string $nama = 'Jam operasi'): string
    {
        return $this->seedMaster('aset_m_jenis_counter', $nama, ['satuan_id' => (string) Str::ulid(), 'satuan' => 'jam']);
    }

    /**
     * Work order yang sudah selesai untuk satu aset dan jenis pekerjaan, sebagai riwayat.
     */
    private function seedWorkOrder(string $asetId, string $jobTypeId, string $status, array $dates = []): string
    {
        $id = (string) Str::ulid();
        DB::table('aset_tr_pemeliharaan_aset')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(), 'kode' => 'WO-'.Str::random(6),
            'legal_entity_id' => $this->legalEntityId, 'responsible_org_unit_id' => $this->unitId,
            'tipe_work_order_id' => $this->seedMaster('aset_m_tipe_work_order', 'Riwayat'),
            'status' => $status,
            ...$dates,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('aset_tr_pemeliharaan_aset_details')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'pemeliharaan_aset_id' => $id,
            'line_number' => 1, 'aset_id' => $asetId, 'maintenance_job_type_id' => $jobTypeId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
