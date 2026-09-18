<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\JenisAset;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceChecklistTemplate;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceChecklistTemplateLine;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceChecklistVariable;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceChecklistVariableValue;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobType;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobTypeJenisAset;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobTypeVariant;
use Modules\Apperp\ManagementAset\Services\DaftarSatuanAset;

final class MaintenanceSetupLinkController extends Controller
{
    public function jobTypeVariants(Request $request, string $jobTypeId): JsonResponse
    {
        $this->jobType($jobTypeId);
        $this->permission($request, 'maintenance-job-types', 'read');

        return response()->json(['data' => MaintenanceJobTypeVariant::query()
            ->where('maintenance_job_type_id', $jobTypeId)
            ->orderBy('kode')->get(['id', 'kode', 'nama', 'keterangan', 'aktif'])]);
    }

    // Persyaratan skill dan sertifikat job type dihapus. Keduanya adalah kompetensi milik
    // Human Resources yang dipasang pada pekerja; menyimpannya sebagai teks bebas di sini
    // melanggar batas modul dan tidak akan pernah cocok dengan kompetensi pekerja. Dibangun
    // ulang sebagai referensi ke Workforce Core ketika kontraknya tersedia.

    public function jobTypeAsetTypes(Request $request, string $jobTypeId): JsonResponse
    {
        $this->jobType($jobTypeId);
        $this->permission($request, 'maintenance-job-types', 'read');

        return response()->json($this->asetTypeTransfer($jobTypeId, 'job_type_id'));
    }

    public function replaceJobTypeAsetTypes(Request $request, string $jobTypeId): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->jobType($jobTypeId);
        $this->permission($request, 'maintenance-job-types', 'update');

        $data = $request->validate($this->asetTypeIdsRules($tenant));
        $this->replaceAsetTypeLink($jobTypeId, $data['jenis_aset_ids']);

        return response()->json($this->asetTypeTransfer($jobTypeId, 'job_type_id'));
    }

    public function jenisAsetAsetTypes(Request $request, string $jenisAsetId): JsonResponse
    {
        $this->jenisAset($jenisAsetId);
        $this->permission($request, 'jenis-aset', 'read');

        return response()->json($this->asetTypeTransfer($jenisAsetId, 'jenis_aset_id'));
    }

    public function replaceJenisAsetAsetTypes(Request $request, string $jenisAsetId): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->jenisAset($jenisAsetId);
        $this->permission($request, 'jenis-aset', 'update');

        $data = $request->validate($this->jobTypeIdsRules($tenant));
        DB::transaction(function () use ($jenisAsetId, $data): void {
            // Dikunci dari sisi job type, bukan sisi jenis aset, karena arah yang
            // satunya juga mengunci job type. Lihat lockJobTypes().
            $current = MaintenanceJobTypeJenisAset::query()
                ->where('jenis_aset_id', $jenisAsetId)
                ->pluck('job_type_id')->all();
            $this->lockJobTypes([...$current, ...$data['jenis_aset_ids']]);

            MaintenanceJobTypeJenisAset::query()->where('jenis_aset_id', $jenisAsetId)->delete();
            foreach ($data['jenis_aset_ids'] as $jobTypeId) {
                $this->jobType($jobTypeId);
                MaintenanceJobTypeJenisAset::query()->create([
                    'job_type_id' => $jobTypeId,
                    'jenis_aset_id' => $jenisAsetId,
                ]);
            }
        });

        return response()->json($this->asetTypeTransfer($jenisAsetId, 'jenis_aset_id'));
    }

    public function variableValues(Request $request, string $variableId): JsonResponse
    {
        $this->record(MaintenanceChecklistVariable::class, $variableId);
        $this->permission($request, 'maintenance-checklist-variables', 'read');

        return response()->json(['data' => MaintenanceChecklistVariableValue::query()
            ->where('variable_id', $variableId)->orderBy('line_number')->get()
            ->map($this->baris(...))]);
    }

    public function replaceVariableValues(Request $request, string $variableId): JsonResponse
    {
        $this->record(MaintenanceChecklistVariable::class, $variableId);
        $this->permission($request, 'maintenance-checklist-variables', 'update');
        $data = $request->validate([
            'values' => ['present', 'array', 'max:100'],
            'values.*.line_number' => ['required', 'numeric', 'min:1'],
            'values.*.value' => ['required', 'string', 'max:255'],
            'values.*.result_code' => ['required', Rule::in(['pass', 'fail', 'none'])],
        ]);
        DB::transaction(function () use ($variableId, $data): void {
            $this->lockRecord(MaintenanceChecklistVariable::class, $variableId);
            MaintenanceChecklistVariableValue::query()->where('variable_id', $variableId)->delete();
            foreach ($data['values'] as $value) {
                MaintenanceChecklistVariableValue::query()->create([
                    'variable_id' => $variableId,
                    'line_number' => $value['line_number'],
                    'value' => trim($value['value']),
                    'result_code' => $value['result_code'],
                ]);
            }
        });

        return $this->variableValues($request, $variableId);
    }

    public function templateLines(Request $request, string $templateId): JsonResponse
    {
        $this->record(MaintenanceChecklistTemplate::class, $templateId);
        $this->permission($request, 'maintenance-checklist-templates', 'read');

        return response()->json(['data' => MaintenanceChecklistTemplateLine::query()
            ->where('template_id', $templateId)->orderBy('line_number')->get()
            ->map($this->baris(...))]);
    }

    public function replaceTemplateLines(Request $request, string $templateId): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->record(MaintenanceChecklistTemplate::class, $templateId);
        $this->permission($request, 'maintenance-checklist-templates', 'update');
        $data = $request->validate([
            'lines' => ['present', 'array', 'max:100'],
            'lines.*.line_number' => ['required', 'numeric', 'min:1'],
            'lines.*.type' => ['required', Rule::in(['header', 'text', 'measurement', 'variable', 'template'])],
            'lines.*.nama' => ['required', 'string', 'max:255'],
            'lines.*.instruksi' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'lines.*.wajib' => ['sometimes', 'boolean'],
            'lines.*.unit_id' => ['sometimes', 'nullable', 'ulid'],
            'lines.*.min_value' => ['sometimes', 'nullable', 'numeric'],
            'lines.*.max_value' => ['sometimes', 'nullable', 'numeric'],
            'lines.*.variable_id' => ['sometimes', 'nullable', 'ulid', Rule::exists('aset_m_maintenance_checklist_variable', 'id')->where('tenant_id', $tenant)],
            'lines.*.nested_template_id' => ['sometimes', 'nullable', 'ulid', Rule::exists('aset_m_maintenance_checklist_template', 'id')->where('tenant_id', $tenant)],
        ], [
            'lines.*.nama.required' => 'Nama baris wajib diisi.',
        ]);
        $unitCodes = $this->measurementUnitCodes($tenant, $data['lines']);

        DB::transaction(function () use ($templateId, $data, $unitCodes): void {
            $this->lockRecord(MaintenanceChecklistTemplate::class, $templateId);
            MaintenanceChecklistTemplateLine::query()->where('template_id', $templateId)->delete();
            foreach ($data['lines'] as $line) {
                $min = $line['min_value'] ?? null;
                $max = $line['max_value'] ?? null;
                if ($line['type'] === 'measurement' && (($min === null) !== ($max === null))) {
                    throw ValidationException::withMessages(['lines' => 'Nilai minimum dan maksimum harus diisi bersama atau dikosongkan bersama.']);
                }
                if ($line['type'] === 'measurement' && $min !== null && (float) $max < (float) $min) {
                    throw ValidationException::withMessages(['lines' => 'Nilai maksimum harus sama dengan atau lebih besar dari nilai minimum.']);
                }
                if ($line['type'] === 'variable' && empty($line['variable_id'])) {
                    throw ValidationException::withMessages(['lines' => 'Baris variabel harus memilih variabel checklist.']);
                }
                if ($line['type'] === 'template' && empty($line['nested_template_id'])) {
                    throw ValidationException::withMessages(['lines' => 'Baris template harus memilih template checklist.']);
                }
                MaintenanceChecklistTemplateLine::query()->create([
                    'template_id' => $templateId,
                    'line_number' => $line['line_number'], 'type' => $line['type'], 'variable_id' => $line['variable_id'] ?? null,
                    'nested_template_id' => $line['nested_template_id'] ?? null,
                    'unit_id' => $line['type'] === 'measurement' ? ($line['unit_id'] ?? null) : null,
                    'unit' => $line['type'] === 'measurement' && ! empty($line['unit_id']) ? ($unitCodes[(string) $line['unit_id']] ?? null) : null,
                    'min_value' => $line['type'] === 'measurement' ? $min : null,
                    'max_value' => $line['type'] === 'measurement' ? $max : null,
                    'nama' => trim($line['nama']),
                    'instruksi' => trim((string) ($line['instruksi'] ?? '')) === '' ? null : trim((string) $line['instruksi']),
                    // Baris judul hanya memberi struktur dan tidak pernah diisi teknisi,
                    // jadi ia tidak boleh dapat ditandai wajib dan menahan penyelesaian.
                    'wajib' => $line['type'] === 'header' ? false : filter_var($line['wajib'] ?? false, FILTER_VALIDATE_BOOL),
                ]);
            }
        });

        return $this->templateLines($request, $templateId);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, string>
     */
    private function measurementUnitCodes(string $tenant, array $lines): array
    {
        $ids = array_values(collect($lines)
            ->filter(fn (array $line): bool => ($line['type'] ?? null) === 'measurement' && ! empty($line['unit_id']))
            ->pluck('unit_id')->map(fn ($id): string => (string) $id)->unique()->values()->all());
        if ($ids === []) {
            return [];
        }

        try {
            return collect(app(DaftarSatuanAset::class)->resolve($tenant, $ids))
                ->mapWithKeys(fn (array $unit, string $id): array => [$id => $unit['code']])->all();
        } catch (\RuntimeException) {
            throw ValidationException::withMessages(['lines' => 'Satuan tidak ditemukan, tidak aktif, atau belum dapat diperiksa.']);
        }
    }

    /**
     * Satu baris apa adanya seperti tersimpan.
     *
     * Kedua endpoint checklist mengembalikan seluruh kolom barisnya, cap waktu termasuk,
     * dan layar sudah membacanya dalam bentuk itu. Menyajikan modelnya akan menulis ulang
     * `created_at` dan `updated_at` ke format lain; atribut mentah menjaganya tetap sama.
     *
     * @return array<string, mixed>
     */
    private function baris(Model $row): array
    {
        return $row->getAttributes();
    }

    /** @return array<string, mixed> */
    private function asetTypeTransfer(string $id, string $column): array
    {
        $selectedIds = MaintenanceJobTypeJenisAset::query()->where($column, $id)
            ->pluck($column === 'job_type_id' ? 'jenis_aset_id' : 'job_type_id')->all();
        $selectedIds = array_map('strval', $selectedIds);
        $lawan = $column === 'job_type_id' ? JenisAset::class : MaintenanceJobType::class;
        $all = $lawan::query()->where('aktif', true)->orderBy('kode')->get(['id', 'kode', 'nama']);

        return ['data' => [
            'remaining' => $all->reject(fn ($item) => in_array((string) $item->id, $selectedIds, true))->values(),
            'selected' => $all->filter(fn ($item) => in_array((string) $item->id, $selectedIds, true))->values(),
        ]];
    }

    /** @param  list<string>  $jenisAsetIds */
    private function replaceAsetTypeLink(string $jobTypeId, array $jenisAsetIds): void
    {
        DB::transaction(function () use ($jobTypeId, $jenisAsetIds): void {
            $this->lockJobTypes([$jobTypeId]);
            MaintenanceJobTypeJenisAset::query()->where('job_type_id', $jobTypeId)->delete();
            foreach ($jenisAsetIds as $jenisAsetId) {
                MaintenanceJobTypeJenisAset::query()->create([
                    'job_type_id' => $jobTypeId,
                    'jenis_aset_id' => $jenisAsetId,
                ]);
            }
        });
    }

    /** @return array<string, list<mixed>> */
    private function asetTypeIdsRules(string $tenant): array
    {
        return [
            'jenis_aset_ids' => ['present', 'array', 'max:200'],
            'jenis_aset_ids.*' => ['required', 'distinct', 'ulid', Rule::exists('aset_m_jenis_aset', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
        ];
    }

    /** @return array<string, list<mixed>> */
    private function jobTypeIdsRules(string $tenant): array
    {
        return [
            'jenis_aset_ids' => ['present', 'array', 'max:200'],
            'jenis_aset_ids.*' => ['required', 'distinct', 'ulid', Rule::exists('aset_m_maintenance_job_type', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
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
     *
     * @param  class-string<Model>  $model
     */
    private function lockRecord(string $model, string $id): void
    {
        $model::query()->whereKey($id)->lockForUpdate()->first();
    }

    /**
     * Mengunci baris job type yang terlibat, selalu terurut menurut id.
     *
     * `aset_m_maintenance_job_type_jenis_aset` disunting dari dua arah: per job type dan
     * per jenis aset. Mengunci baris pemilik masing-masing arah tidak menolong,
     * karena keduanya akan memegang kunci pada tabel yang berbeda dan tetap saling
     * menimpa. Karena itu kedua arah mengunci sisi yang sama, yaitu job type: dua
     * operasi yang dapat menyentuh baris kaitan `(j, a)` yang sama pasti sama-sama
     * memuat `j` dalam himpunan kuncinya, sehingga berurutan.
     *
     * Urutan `id` yang tetap mencegah dua transaksi mengambil kunci yang sama dalam
     * urutan berlawanan dan saling menunggu selamanya.
     *
     * @param  array<int, mixed>  $jobTypeIds  id dari kiriman maupun dari `pluck()`, jadi
     *                                         bentuknya baru dipastikan di dalam
     */
    private function lockJobTypes(array $jobTypeIds): void
    {
        $ids = array_values(array_unique(array_map('strval', $jobTypeIds)));
        if ($ids === []) {
            return;
        }

        // `withTrashed()`: baris kaitan tetap ada setelah job type-nya diarsipkan, dan
        // yang harus dikunci adalah pemilik baris kaitan itu — bukan hanya job type yang
        // masih aktif. Tanpa ini himpunan kunci kedua arah tidak lagi pasti beririsan.
        MaintenanceJobType::query()->withTrashed()
            ->whereKey($ids)
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

    /** @param  class-string<Model>  $model */
    private function record(string $model, string $id): void
    {
        abort_unless($model::query()->whereKey($id)->exists(), 404);
    }

    private function jobType(string $id): void
    {
        $this->record(MaintenanceJobType::class, $id);
    }

    private function jenisAset(string $id): void
    {
        $this->record(JenisAset::class, $id);
    }
}
