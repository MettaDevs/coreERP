<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Tests\TestCase;

class MasterReadQueryBoundsTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    public function test_job_type_counts_do_not_query_each_returned_record(): void
    {
        $tenant = $this->buatTenantUji();
        $this->sebagaiPengguna($tenant, ['management-aset.maintenance-job-types.read']);
        $ids = [];
        for ($i = 0; $i < 25; $i++) {
            $type = $this->seedMaster($tenant, 'aset_m_maintenance_job_type');
            $ids[] = $type;
            $this->seedMaster($tenant, 'aset_m_maintenance_job_type_variant', ['maintenance_job_type_id' => $type]);
            $this->seedMaster($tenant, 'aset_m_maintenance_job_type_variant', ['maintenance_job_type_id' => $type, 'deleted_at' => now()]);
        }
        $otherTenant = $this->buatTenantUji();
        $foreign = $this->seedMaster($otherTenant, 'aset_m_maintenance_job_type');
        $this->seedMaster($otherTenant, 'aset_m_maintenance_job_type_variant', ['maintenance_job_type_id' => $foreign]);

        DB::enableQueryLog();
        try {
            foreach ([1, 10, 25] as $size) {
                DB::flushQueryLog();
                $rows = $this->getJson('/api/modules/management-aset/v1/maintenance-job-types?per_page='.$size)
                    ->assertOk()->assertJsonCount($size, 'data')->json('data');
                $queries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select')
                    && str_contains($query['query'], 'aset_m_maintenance_job_type'));
                $this->assertLessThanOrEqual(2, count($queries));
                foreach ($rows as $row) {
                    $this->assertContains($row['id'], $ids);
                    $this->assertSame(1, $row['variants_count']);
                }
            }
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_condition_replace_batches_reference_validation(): void
    {
        $tenant = $this->buatTenantUji();
        $this->sebagaiPengguna($tenant, ['management-aset.jenis-aset.update', 'management-aset.kondisi-aset.read']);
        $conditions = [];
        for ($i = 0; $i < 25; $i++) {
            $conditions[] = $this->seedMaster($tenant, 'aset_m_kondisi_aset');
        }
        DB::enableQueryLog();
        try {
            foreach ([1, 10, 25] as $size) {
                $type = $this->seedMaster($tenant, 'aset_m_jenis_aset');
                DB::flushQueryLog();
                $this->putJson('/api/modules/management-aset/v1/jenis-aset/'.$type.'/conditions', ['version' => 1, 'condition_ids' => array_slice($conditions, 0, $size)])
                    ->assertOk()->assertJsonCount($size, 'data.selected');
                $queries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select')
                    && (str_contains($query['query'], 'aset_m_kondisi_aset') || str_contains($query['query'], 'aset_m_jenis_aset_kondisi')));
                $this->assertLessThanOrEqual(6, count($queries));
            }
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_checklist_counts_do_not_query_each_returned_record(): void
    {
        $tenant = $this->buatTenantUji();
        foreach ([
            ['maintenance-checklist-templates', 'aset_m_maintenance_checklist_template', 'aset_m_maintenance_checklist_template_line', 'template_id', 'checks_count', ['line_number' => 1, 'type' => 'text', 'nama' => 'Langkah']],
            ['maintenance-checklist-variables', 'aset_m_maintenance_checklist_variable', 'aset_m_maintenance_checklist_variable_value', 'variable_id', 'values_count', ['line_number' => 1, 'value' => 'Baik', 'result_code' => 'passed']],
        ] as [$resource, $parent, $child, $key, $count, $extra]) {
            $this->sebagaiPengguna($tenant, ['management-aset.'.$resource.'.read']);
            for ($i = 0; $i < 25; $i++) {
                $id = $this->seedMaster($tenant, $parent);
                DB::table($child)->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenant, $key => $id, ...$extra]);
            }
            DB::enableQueryLog();
            try {
                foreach ([1, 10, 25] as $size) {
                    DB::flushQueryLog();
                    $rows = $this->getJson('/api/modules/management-aset/v1/'.$resource.'?per_page='.$size)
                        ->assertOk()->assertJsonCount($size, 'data')->json('data');
                    $queries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select')
                        && (str_contains($query['query'], $parent) || str_contains($query['query'], $child)));
                    $this->assertLessThanOrEqual(2, count($queries));
                    $this->assertSame([1], array_values(array_unique(array_column($rows, $count))));
                }
            } finally {
                DB::disableQueryLog();
            }
        }
    }

    /** @param array<string, mixed> $extra */
    private function seedMaster(string $tenant, string $table, array $extra = []): string
    {
        $id = (string) Str::ulid();
        DB::table($table)->insert([
            'id' => $id, 'tenant_id' => $tenant, 'creation_key' => 'seed-'.$id,
            'kode' => $id, 'nama' => 'Fixture', 'aktif' => true,
            'created_at' => now(), 'updated_at' => now(), ...$extra,
        ]);

        return $id;
    }
}
