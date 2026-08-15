<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class MaintenanceSetupLinkController extends Controller
{
    public function jobTypeVariants(Request $request, string $jobTypeId): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->jobType($tenant, $jobTypeId);
        $this->permission($request, 'maintenance-job-types', 'read');

        return response()->json(['data' => DB::table('m_maintenance_job_type_variant')
            ->where(['tenant_id' => $tenant, 'maintenance_job_type_id' => $jobTypeId])
            ->whereNull('deleted_at')->orderBy('kode')->get(['id', 'kode', 'nama', 'keterangan', 'aktif'])]);
    }

    // Persyaratan skill dan sertifikat job type dihapus. Keduanya adalah kompetensi milik
    // Human Resources yang dipasang pada pekerja; menyimpannya sebagai teks bebas di sini
    // melanggar batas modul dan tidak akan pernah cocok dengan kompetensi pekerja. Dibangun
    // ulang sebagai referensi ke Workforce Core ketika kontraknya tersedia.

    public function jobTypeAssetTypes(Request $request, string $jobTypeId): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->jobType($tenant, $jobTypeId);
        $this->permission($request, 'maintenance-job-types', 'read');

        return response()->json($this->assetTypeTransfer($tenant, $jobTypeId, 'job_type_id'));
    }

    public function replaceJobTypeAssetTypes(Request $request, string $jobTypeId): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->jobType($tenant, $jobTypeId);
        $this->permission($request, 'maintenance-job-types', 'update');

        $data = $request->validate($this->assetTypeIdsRules($tenant));
        $this->replaceAssetTypeLink($tenant, $jobTypeId, $data['jenis_aset_ids']);

        return response()->json($this->assetTypeTransfer($tenant, $jobTypeId, 'job_type_id'));
    }

    public function jenisAsetAssetTypes(Request $request, string $jenisAsetId): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->jenisAset($tenant, $jenisAsetId);
        $this->permission($request, 'jenis-aset', 'read');

        return response()->json($this->assetTypeTransfer($tenant, $jenisAsetId, 'jenis_aset_id'));
    }

    public function replaceJenisAsetAssetTypes(Request $request, string $jenisAsetId): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->jenisAset($tenant, $jenisAsetId);
        $this->permission($request, 'jenis-aset', 'update');

        $data = $request->validate($this->jobTypeIdsRules($tenant));
        DB::transaction(function () use ($tenant, $jenisAsetId, $data): void {
            // Dikunci dari sisi job type, bukan sisi jenis aset, karena arah yang
            // satunya juga mengunci job type. Lihat lockJobTypes().
            $current = DB::table('m_maintenance_job_type_asset_type')
                ->where(['tenant_id' => $tenant, 'jenis_aset_id' => $jenisAsetId])
                ->pluck('job_type_id')->all();
            $this->lockJobTypes($tenant, [...$current, ...$data['jenis_aset_ids']]);

            DB::table('m_maintenance_job_type_asset_type')
                ->where(['tenant_id' => $tenant, 'jenis_aset_id' => $jenisAsetId])->delete();
            foreach ($data['jenis_aset_ids'] as $jobTypeId) {
                $this->jobType($tenant, $jobTypeId);
                DB::table('m_maintenance_job_type_asset_type')->insert([
                    'tenant_id' => $tenant, 'job_type_id' => $jobTypeId, 'jenis_aset_id' => $jenisAsetId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return response()->json($this->assetTypeTransfer($tenant, $jenisAsetId, 'jenis_aset_id'));
    }

    public function variableValues(Request $request, string $variableId): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->record('m_maintenance_checklist_variable', $tenant, $variableId);
        $this->permission($request, 'maintenance-checklist-variables', 'read');

        return response()->json(['data' => DB::table('m_maintenance_checklist_variable_value')
            ->where(['tenant_id' => $tenant, 'variable_id' => $variableId])->orderBy('line_number')->get()]);
    }

    public function replaceVariableValues(Request $request, string $variableId): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->record('m_maintenance_checklist_variable', $tenant, $variableId);
        $this->permission($request, 'maintenance-checklist-variables', 'update');
        $data = $request->validate([
            'values' => ['present', 'array', 'max:100'],
            'values.*.line_number' => ['required', 'numeric', 'min:1'],
            'values.*.value' => ['required', 'string', 'max:255'],
            'values.*.result_code' => ['required', Rule::in(['pass', 'fail'])],
        ]);
        DB::transaction(function () use ($tenant, $variableId, $data): void {
            $this->lockRecord('m_maintenance_checklist_variable', $tenant, $variableId);
            DB::table('m_maintenance_checklist_variable_value')->where(['tenant_id' => $tenant, 'variable_id' => $variableId])->delete();
            foreach ($data['values'] as $value) {
                DB::table('m_maintenance_checklist_variable_value')->insert([
                    'id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'variable_id' => $variableId,
                    'line_number' => $value['line_number'], 'value' => trim($value['value']), 'result_code' => $value['result_code'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return $this->variableValues($request, $variableId);
    }

    public function templateLines(Request $request, string $templateId): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->record('m_maintenance_checklist_template', $tenant, $templateId);
        $this->permission($request, 'maintenance-checklist-templates', 'read');

        return response()->json(['data' => DB::table('m_maintenance_checklist_template_line')
            ->where(['tenant_id' => $tenant, 'template_id' => $templateId])->orderBy('line_number')->get()]);
    }

    public function replaceTemplateLines(Request $request, string $templateId): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->record('m_maintenance_checklist_template', $tenant, $templateId);
        $this->permission($request, 'maintenance-checklist-templates', 'update');
        $data = $request->validate([
            'lines' => ['present', 'array', 'max:100'],
            'lines.*.line_number' => ['required', 'numeric', 'min:1'],
            'lines.*.type' => ['required', Rule::in(['header', 'text', 'measurement', 'variable', 'template'])],
            'lines.*.nama' => ['required', 'string', 'max:255'],
            'lines.*.instruksi' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'lines.*.wajib' => ['sometimes', 'boolean'],
            'lines.*.unit' => ['sometimes', 'nullable', 'string', 'max:40'],
            'lines.*.variable_id' => ['sometimes', 'nullable', 'ulid', Rule::exists('m_maintenance_checklist_variable', 'id')->where('tenant_id', $tenant)],
            'lines.*.nested_template_id' => ['sometimes', 'nullable', 'ulid', Rule::exists('m_maintenance_checklist_template', 'id')->where('tenant_id', $tenant)],
        ]);
        DB::transaction(function () use ($tenant, $templateId, $data): void {
            $this->lockRecord('m_maintenance_checklist_template', $tenant, $templateId);
            DB::table('m_maintenance_checklist_template_line')->where(['tenant_id' => $tenant, 'template_id' => $templateId])->delete();
            foreach ($data['lines'] as $line) {
                if ($line['type'] === 'measurement' && empty($line['unit'])) {
                    throw ValidationException::withMessages(['lines' => 'Baris pengukuran harus memiliki satuan.']);
                }
                if ($line['type'] === 'variable' && empty($line['variable_id'])) {
                    throw ValidationException::withMessages(['lines' => 'Baris variabel harus memilih variabel checklist.']);
                }
                DB::table('m_maintenance_checklist_template_line')->insert([
                    'id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'template_id' => $templateId,
                    'line_number' => $line['line_number'], 'type' => $line['type'], 'variable_id' => $line['variable_id'] ?? null,
                    'nested_template_id' => $line['nested_template_id'] ?? null, 'unit' => $line['unit'] ?? null,
                    'nama' => trim($line['nama']),
                    'instruksi' => trim((string) ($line['instruksi'] ?? '')) === '' ? null : trim((string) $line['instruksi']),
                    // Baris judul hanya memberi struktur dan tidak pernah diisi teknisi,
                    // jadi ia tidak boleh dapat ditandai wajib dan menahan penyelesaian.
                    'wajib' => $line['type'] === 'header' ? false : filter_var($line['wajib'] ?? false, FILTER_VALIDATE_BOOL),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return $this->templateLines($request, $templateId);
    }

    private function assetTypeTransfer(string $tenant, string $id, string $column): array
    {
        $selectedIds = DB::table('m_maintenance_job_type_asset_type')->where(['tenant_id' => $tenant, $column => $id])->pluck($column === 'job_type_id' ? 'jenis_aset_id' : 'job_type_id')->all();
        $selectedIds = array_map('strval', $selectedIds);
        $all = DB::table($column === 'job_type_id' ? 'm_jenis_aset' : 'm_maintenance_job_type')
            ->where(['tenant_id' => $tenant, 'aktif' => true])->whereNull('deleted_at')->orderBy('kode')->get(['id', 'kode', 'nama']);

        return ['data' => [
            'remaining' => $all->reject(fn ($item) => in_array((string) $item->id, $selectedIds, true))->values(),
            'selected' => $all->filter(fn ($item) => in_array((string) $item->id, $selectedIds, true))->values(),
        ]];
    }

    private function replaceAssetTypeLink(string $tenant, string $jobTypeId, array $jenisAsetIds): void
    {
        DB::transaction(function () use ($tenant, $jobTypeId, $jenisAsetIds): void {
            $this->lockJobTypes($tenant, [$jobTypeId]);
            DB::table('m_maintenance_job_type_asset_type')->where(['tenant_id' => $tenant, 'job_type_id' => $jobTypeId])->delete();
            foreach ($jenisAsetIds as $jenisAsetId) {
                DB::table('m_maintenance_job_type_asset_type')->insert([
                    'tenant_id' => $tenant, 'job_type_id' => $jobTypeId, 'jenis_aset_id' => $jenisAsetId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    private function assetTypeIdsRules(string $tenant): array
    {
        return [
            'jenis_aset_ids' => ['present', 'array', 'max:200'],
            'jenis_aset_ids.*' => ['required', 'distinct', 'ulid', Rule::exists('m_jenis_aset', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
        ];
    }

    private function jobTypeIdsRules(string $tenant): array
    {
        return [
            'jenis_aset_ids' => ['present', 'array', 'max:200'],
            'jenis_aset_ids.*' => ['required', 'distinct', 'ulid', Rule::exists('m_maintenance_job_type', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
        ];
    }

    /**
     * Menahan baris pemilik selama transaksi penggantian berjalan.
     *
     * Setiap endpoint "replace" di sini menghapus lalu menyisipkan ulang. Tanpa
     * kunci, dua permintaan atas pemilik yang sama bisa saling menyela: yang satu
     * menghapus, yang lain menghapus dan menyisipkan, lalu yang pertama menyisipkan
     * di atasnya. Hasilnya gabungan dua himpunan — bukan kehendak salah satu
     * pengguna, dan tidak ada batasan basis data yang menolaknya karena tiap baris
     * masing-masing sah. Feature test tidak akan pernah melihat ini: ia menjalankan
     * satu permintaan pada satu proses.
     */
    private function lockRecord(string $table, string $tenant, string $id): void
    {
        DB::table($table)->where(['tenant_id' => $tenant, 'id' => $id])->lockForUpdate()->first();
    }

    /**
     * Mengunci baris job type yang terlibat, selalu terurut menurut id.
     *
     * `m_maintenance_job_type_asset_type` disunting dari dua arah: per job type dan
     * per jenis aset. Mengunci baris pemilik masing-masing arah tidak menolong,
     * karena keduanya akan memegang kunci pada tabel yang berbeda dan tetap saling
     * menimpa. Karena itu kedua arah mengunci sisi yang sama, yaitu job type: dua
     * operasi yang dapat menyentuh baris kaitan `(j, a)` yang sama pasti sama-sama
     * memuat `j` dalam himpunan kuncinya, sehingga berurutan.
     *
     * Urutan `id` yang tetap mencegah dua transaksi mengambil kunci yang sama dalam
     * urutan berlawanan dan saling menunggu selamanya.
     */
    private function lockJobTypes(string $tenant, array $jobTypeIds): void
    {
        $ids = array_values(array_unique(array_map('strval', $jobTypeIds)));
        if ($ids === []) {
            return;
        }

        DB::table('m_maintenance_job_type')
            ->where('tenant_id', $tenant)->whereIn('id', $ids)
            ->orderBy('id')->lockForUpdate()->get(['id']);
    }

    private function tenant(Request $request): string
    {
        return (string) $request->attributes->get('coreerp.tenant_id');
    }

    private function permission(Request $request, string $resource, string $action): void
    {
        abort_unless(in_array('management-aset.'.$resource.'.'.$action, $request->attributes->get('coreerp.permissions', []), true), 403);
    }

    private function record(string $table, string $tenant, string $id): void
    {
        abort_unless(DB::table($table)->where(['tenant_id' => $tenant, 'id' => $id])->whereNull('deleted_at')->exists(), 404);
    }

    private function jobType(string $tenant, string $id): void
    {
        $this->record('m_maintenance_job_type', $tenant, $id);
    }

    private function jenisAset(string $tenant, string $id): void
    {
        $this->record('m_jenis_aset', $tenant, $id);
    }
}
