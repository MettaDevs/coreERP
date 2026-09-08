<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Apperp\ManagementAset\Http\Controllers\MasterDataController;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobTypeDefault;
use Modules\Apperp\ManagementAset\Models\MasterData;
use Modules\Apperp\ManagementAset\Services\NumberSequenceClient;
use Modules\Apperp\ManagementAset\Support\MasterParent;

class MaintenanceJobTypeDefaultController extends MasterDataController
{
    protected function resource(): string
    {
        return 'maintenance-job-type-defaults';
    }

    protected function model(): string
    {
        return MaintenanceJobTypeDefault::class;
    }

    protected function parentMasters(): array
    {
        return [
            new MasterParent('aset_m_maintenance_job_type', 'maintenance_job_type_id', 'maintenanceJobType', 'Jenis pekerjaan maintenance'),
            new MasterParent('aset_m_maintenance_job_type_variant', 'variant_id', 'variant', 'Varian job type', false),
            new MasterParent('aset_m_maintenance_checklist_template', 'checklist_template_id', 'checklistTemplate', 'Template checklist', false),
        ];
    }

    protected function extraRules(string $tenantId, bool $creating): array
    {
        return [
            'trade' => ['sometimes', 'nullable', 'string', 'max:100'],
            'functional_location_id' => ['sometimes', 'nullable', 'ulid', Rule::exists('aset_m_lokasi_aset', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'jenis_aset_id' => ['sometimes', 'nullable', 'ulid', Rule::exists('aset_m_jenis_aset', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'pabrikan_aset_id' => ['sometimes', 'nullable', 'ulid', Rule::exists('aset_m_pabrikan_aset', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'model_aset_id' => ['sometimes', 'nullable', 'ulid', Rule::exists('aset_m_model_aset', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'asset_id' => ['sometimes', 'nullable', 'ulid', Rule::exists('aset_tr_penerimaan_aset', 'id')->where('tenant_id', $tenantId)],
            'hours' => ['sometimes', 'numeric', 'min:0', 'max:999999999.99'],
            'items_count' => ['sometimes', 'integer', 'min:0'],
            'expenses_count' => ['sometimes', 'integer', 'min:0'],
            'fees_count' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    protected function afterWriteValidation(array $data, string $tenantId, bool $creating, ?MasterData $record = null): void
    {
        $jobTypeId = $data['maintenance_job_type_id'] ?? $record?->maintenance_job_type_id;
        $variantId = array_key_exists('variant_id', $data) ? $data['variant_id'] : $record?->variant_id;
        if ($variantId !== null && ! DB::table('aset_m_maintenance_job_type_variant')->where([
            'tenant_id' => $tenantId,
            'id' => $variantId,
            'maintenance_job_type_id' => $jobTypeId,
        ])->whereNull('deleted_at')->exists()) {
            abort(422, 'Varian job type harus berasal dari jenis pekerjaan yang dipilih.');
        }
    }

    protected function extraPayload(array $data): array
    {
        $payload = [];
        foreach (['trade', 'functional_location_id', 'jenis_aset_id', 'pabrikan_aset_id', 'model_aset_id', 'asset_id', 'hours', 'items_count', 'expenses_count', 'fees_count'] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $payload[$key] = is_string($data[$key]) && trim($data[$key]) === '' ? null : $data[$key];
        }

        return $payload;
    }

    protected function extraPresent(MasterData $record): array
    {
        return [
            'trade' => $record->trade,
            'functional_location_id' => $record->functional_location_id,
            'jenis_aset_id' => $record->jenis_aset_id,
            'pabrikan_aset_id' => $record->pabrikan_aset_id,
            'model_aset_id' => $record->model_aset_id,
            'asset_id' => $record->asset_id,
            'hours' => $record->hours,
            'items_count' => (int) $record->items_count,
            'expenses_count' => (int) $record->expenses_count,
            'fees_count' => (int) $record->fees_count,
        ];
    }

    public function copy(Request $request, string $id): JsonResponse
    {
        $this->requirePermissionForCopy($request);
        $tenantId = (string) $request->attributes->get('coreerp.tenant_id');
        $source = MaintenanceJobTypeDefault::query()->where('tenant_id', $tenantId)->findOrFail($id);
        $data = $request->validate(['nama' => ['required', 'string', 'max:150']]);
        $creationKey = (string) $request->header('Idempotency-Key');
        validator(['key' => $creationKey], ['key' => ['required', 'string', 'max:133', 'regex:/^[A-Za-z0-9._:-]+$/']])->validate();
        $numbers = app(NumberSequenceClient::class);
        $code = $numbers->issue('management-aset.maintenance-job-type-defaults', $tenantId, 'maintenance-job-type-defaults:'.$creationKey);
        $copy = $source->replicate(['id', 'created_at', 'updated_at', 'deleted_at']);
        $copy->fill(['tenant_id' => $tenantId, 'creation_key' => $creationKey, 'kode' => $code, 'nama' => trim($data['nama'])]);
        $copy->save();

        return response()->json(['data' => $copy], 201);
    }

    private function requirePermissionForCopy(Request $request): void
    {
        abort_unless(in_array('management-aset.maintenance-job-type-defaults.create', $request->attributes->get('coreerp.permissions', []), true), 403);
    }
}
