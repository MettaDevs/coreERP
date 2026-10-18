<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class MasterDraftDetailsTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
    }

    public function test_manufacturer_and_models_are_created_once_in_one_save(): void
    {
        $input = ['nama' => 'Manufacturer aggregate', 'details' => ['models' => ['rows' => [
            ['nama' => 'Model first'], ['nama' => 'Model second'],
        ]]]];
        $created = $this->createMaster('pabrikan-aset', $input, 'manufacturer-aggregate')->assertCreated()->json('data');
        $this->assertSame(2, DB::table('aset_m_model_aset')->where('pabrikan_aset_id', $created['id'])->count());
        $this->createMaster('pabrikan-aset', $input, 'manufacturer-aggregate')->assertOk()->assertJsonPath('data.id', $created['id']);
        $this->assertSame(2, DB::table('aset_m_model_aset')->where('pabrikan_aset_id', $created['id'])->count());
    }

    public function test_invalid_model_rolls_back_parent_first_child_and_number(): void
    {
        $this->createMaster('pabrikan-aset', ['nama' => 'Invalid manufacturer', 'details' => ['models' => ['rows' => [
            ['nama' => 'Must roll back'], ['nama' => ''],
        ]]]], 'manufacturer-retry')->assertUnprocessable()->assertJsonValidationErrors('details.models.rows.1.nama');
        $this->assertDatabaseMissing('aset_m_pabrikan_aset', ['nama' => 'Invalid manufacturer']);
        $this->assertDatabaseMissing('aset_m_model_aset', ['nama' => 'Must roll back']);
        $this->createMaster('pabrikan-aset', ['nama' => 'Valid manufacturer', 'details' => ['models' => ['rows' => [['nama' => 'Valid model']]]]], 'manufacturer-retry')
            ->assertCreated();
        $this->assertSame(1, (int) DB::table('number_sequence_issues')->where('idempotency_key', 'pabrikan-aset:manufacturer-retry')->value('numeric_value'));
    }

    public function test_asset_type_links_share_the_new_parent_and_canonical_validation(): void
    {
        $attribute = $this->createMaster('tipe-atribut', ['nama' => 'Reference attribute', 'data_type' => 'string'])->assertCreated()->json('data.id');
        $condition = $this->createMaster('kondisi-aset', ['nama' => 'Reference condition'])->assertCreated()->json('data.id');
        $job = $this->createMaster('maintenance-job-types', ['nama' => 'Reference job'])->assertCreated()->json('data.id');
        $type = $this->createMaster('jenis-aset', ['nama' => 'Aggregate asset type', 'details' => [
            'attributes' => ['rows' => [['tipe_atribut_id' => $attribute, 'wajib' => true, 'urutan' => 1]]],
            'conditions' => ['condition_ids' => [$condition]],
            'job_types' => ['jenis_aset_ids' => [$job]],
        ]])->assertCreated()->json('data');
        $this->assertDatabaseHas('aset_m_jenis_aset_atribut', ['jenis_aset_id' => $type['id'], 'tipe_atribut_id' => $attribute, 'wajib' => true]);
        $this->assertDatabaseHas('aset_m_jenis_aset_kondisi', ['jenis_aset_id' => $type['id'], 'kondisi_aset_id' => $condition]);
        $this->assertDatabaseHas('aset_m_maintenance_job_type_jenis_aset', ['jenis_aset_id' => $type['id'], 'job_type_id' => $job]);
    }

    public function test_checklist_parent_and_rows_are_atomic_and_values_replay_is_safe(): void
    {
        $variable = $this->createMaster('maintenance-checklist-variables', ['nama' => 'Aggregate variable', 'details' => ['values' => ['values' => [
            ['line_number' => 1, 'value' => 'Pass', 'result_code' => 'pass'],
            ['line_number' => 2, 'value' => 'Fail', 'result_code' => 'fail'],
        ]]]])->assertCreated()->json('data');
        $this->assertSame(2, DB::table('aset_m_maintenance_checklist_variable_value')->where('variable_id', $variable['id'])->count());
        $this->createMaster('maintenance-checklist-templates', ['nama' => 'Invalid template', 'details' => ['lines' => ['lines' => [
            ['line_number' => 1, 'type' => 'text', 'nama' => 'Valid line'],
            ['line_number' => 2, 'type' => 'measurement', 'nama' => 'Invalid bounds', 'min_value' => 1],
        ]]]])->assertUnprocessable()->assertJsonValidationErrors('details.lines.lines');
        $this->assertDatabaseMissing('aset_m_maintenance_checklist_template', ['nama' => 'Invalid template']);
        $template = $this->createMaster('maintenance-checklist-templates', ['nama' => 'Aggregate template', 'details' => ['lines' => ['lines' => [
            ['line_number' => 1, 'type' => 'variable', 'nama' => 'Inspect', 'variable_id' => $variable['id']],
        ]]]])->assertCreated()->json('data');
        $this->assertDatabaseHas('aset_m_maintenance_checklist_template_line', ['template_id' => $template['id'], 'variable_id' => $variable['id']]);
    }

    public function test_maintenance_plan_and_lines_and_targets_commit_together(): void
    {
        $job = $this->createMaster('maintenance-job-types', ['nama' => 'Plan job'])->assertCreated()->json('data.id');
        $orderType = $this->createMaster('tipe-work-order', ['nama' => 'Plan order type'])->assertCreated()->json('data.id');
        $assetType = $this->createMaster('jenis-aset', ['nama' => 'Plan asset type'])->assertCreated()->json('data.id');
        $plan = $this->createMaster('rencana-pemeliharaan', ['nama' => 'Aggregate plan', 'tanggal_mulai' => '2026-01-01', 'details' => [
            'lines' => ['lines' => [['dasar' => 'tanggal_mulai', 'interval' => 1, 'satuan_interval' => 'bulan', 'maintenance_job_type_id' => $job, 'tipe_work_order_id' => $orderType]]],
            'targets' => ['targets' => [['jenis_aset_id' => $assetType, 'tanggal_mulai' => '2026-01-01']]],
        ]])->assertCreated()->json('data');
        $this->assertDatabaseHas('aset_m_rencana_pemeliharaan_baris', ['rencana_pemeliharaan_id' => $plan['id'], 'maintenance_job_type_id' => $job]);
        $this->assertDatabaseHas('aset_m_rencana_pemeliharaan_objek', ['rencana_pemeliharaan_id' => $plan['id'], 'jenis_aset_id' => $assetType]);
    }

    public function test_job_type_can_include_variants_and_asset_type_links(): void
    {
        $type = $this->createMaster('jenis-aset', ['nama' => 'Job asset type'])->assertCreated()->json('data.id');
        $job = $this->createMaster('maintenance-job-types', ['nama' => 'Aggregate job', 'details' => [
            'variants' => ['rows' => [['nama' => 'Weekly'], ['nama' => 'Monthly']]],
            'asset_types' => ['jenis_aset_ids' => [$type]],
        ]])->assertCreated()->json('data');
        $this->assertSame(2, DB::table('aset_m_maintenance_job_type_variant')->where('maintenance_job_type_id', $job['id'])->count());
        $this->assertDatabaseHas('aset_m_maintenance_job_type_jenis_aset', ['job_type_id' => $job['id'], 'jenis_aset_id' => $type]);
    }

    public function test_child_permission_and_foreign_references_roll_back_parent(): void
    {
        $this->sebagaiPengguna($this->tenantId, ['management-aset.pabrikan-aset.read', 'management-aset.pabrikan-aset.create'])
            ->withHeader('Idempotency-Key', 'forbidden-aggregate')
            ->postJson('/api/modules/management-aset/v1/pabrikan-aset', ['nama' => 'Forbidden manufacturer', 'details' => ['models' => ['rows' => [['nama' => 'Forbidden model']]]]])
            ->assertForbidden();
        $this->assertDatabaseMissing('aset_m_pabrikan_aset', ['nama' => 'Forbidden manufacturer']);
        $other = $this->buatTenantUji();
        $foreign = $this->createMaster('kondisi-aset', ['nama' => 'Foreign condition'], tenant: $other)->assertCreated()->json('data.id');
        $this->createMaster('jenis-aset', ['nama' => 'Foreign link blocked', 'details' => ['conditions' => ['condition_ids' => [$foreign]]]])
            ->assertUnprocessable()->assertJsonValidationErrors('details.conditions.condition_ids.0');
        $this->assertDatabaseMissing('aset_m_jenis_aset', ['nama' => 'Foreign link blocked']);
    }

    public function test_duplicate_staged_rows_return_validation_errors_and_leave_no_partial_parents(): void
    {
        $this->createMaster('maintenance-checklist-variables', ['nama' => 'Duplicate variable', 'details' => ['values' => ['values' => [
            ['line_number' => 1, 'value' => 'One', 'result_code' => 'pass'],
            ['line_number' => 1.0, 'value' => 'Two', 'result_code' => 'fail'],
        ]]]])->assertUnprocessable()->assertJsonValidationErrors('details.values.values.1.line_number');
        $this->assertDatabaseMissing('aset_m_maintenance_checklist_variable', ['nama' => 'Duplicate variable']);
        $this->createMaster('maintenance-checklist-templates', ['nama' => 'Duplicate template', 'details' => ['lines' => ['lines' => [
            ['line_number' => 1, 'type' => 'text', 'nama' => 'One'],
            ['line_number' => 1, 'type' => 'text', 'nama' => 'Two'],
        ]]]])->assertUnprocessable()->assertJsonValidationErrors('details.lines.lines.1.line_number');
        $this->assertDatabaseMissing('aset_m_maintenance_checklist_template', ['nama' => 'Duplicate template']);
        $attribute = $this->createMaster('tipe-atribut', ['nama' => 'Unique attribute', 'data_type' => 'string'])->assertCreated()->json('data.id');
        $this->createMaster('jenis-aset', ['nama' => 'Duplicate attributes', 'details' => ['attributes' => ['rows' => [
            ['tipe_atribut_id' => $attribute, 'wajib' => true],
            ['tipe_atribut_id' => $attribute, 'wajib' => false],
        ]]]])->assertUnprocessable()->assertJsonValidationErrors('details.attributes.rows.1.tipe_atribut_id');
        $this->assertDatabaseMissing('aset_m_jenis_aset', ['nama' => 'Duplicate attributes']);
        $this->createMaster('tipe-atribut', ['nama' => 'Duplicate values', 'data_type' => 'string', 'details' => ['values' => ['rows' => [
            ['nilai' => 'Same', 'urutan' => 1], ['nilai' => 'Same', 'urutan' => 2],
        ]]]])->assertUnprocessable()->assertJsonValidationErrors('details.values.rows.1.nilai');
        $this->assertDatabaseMissing('aset_m_tipe_atribut', ['nama' => 'Duplicate values']);
        $this->createMaster('pabrikan-aset', ['nama' => 'Duplicate model codes', 'details' => ['models' => ['rows' => [
            ['nama' => 'Model one', 'kode' => 'SAME'], ['nama' => 'Model two', 'kode' => 'SAME'],
        ]]]])->assertUnprocessable()->assertJsonValidationErrors('details.models.rows.1.kode');
        $this->assertDatabaseMissing('aset_m_pabrikan_aset', ['nama' => 'Duplicate model codes']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function createMaster(string $resource, array $payload, ?string $key = null, ?string $tenant = null): TestResponse
    {
        $permissions = [];
        foreach (['pabrikan-aset', 'model-aset', 'jenis-aset', 'tipe-atribut', 'kondisi-aset', 'maintenance-job-types', 'maintenance-job-type-variants', 'maintenance-checklist-variables', 'maintenance-checklist-templates', 'tipe-work-order', 'rencana-pemeliharaan'] as $name) {
            foreach (['read', 'create', 'update', 'archive'] as $action) {
                $permissions[] = 'management-aset.'.$name.'.'.$action;
            }
        }

        return $this->sebagaiPengguna($tenant ?? $this->tenantId, $permissions)
            ->withHeader('Idempotency-Key', $key ?? 'aggregate-'.Str::lower(Str::random(16)))
            ->postJson('/api/modules/management-aset/v1/'.$resource, $payload);
    }
}
